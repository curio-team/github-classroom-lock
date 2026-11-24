<?php

namespace App\Services\Provisioning;

use App\Enums\GroupStatus;
use App\Enums\ProvisioningStep;
use App\GitHub\GitHub;
use App\GitHub\GitHubClient;
use App\GitHub\GitHubException;
use App\GitHub\RateLimitedException;
use App\Models\Group;
use App\Services\Audit;
use Throwable;

/**
 * Creates the team, private repository and Project of a group, and wires
 * them together.
 *
 * Every step is idempotent: it first looks for what it is about to create
 * (by stored ID, then by its predictable name) and only creates it when
 * missing. A failed step is recorded on the group; running provisioning
 * again resumes without creating duplicates.
 */
class GroupProvisioner
{
    public const PROJECTS_DISABLED = 'Projects staan uit voor de organisatie. Zet ze aan met de knop bovenaan deze stap (of op GitHub: organisatie → Settings → Projects) en probeer het opnieuw.';

    private ?array $organization = null;

    public function __construct(private readonly GitHub $github)
    {
    }

    /**
     * @return bool whether all steps succeeded
     *
     * @throws RateLimitedException so queued jobs can retry later
     */
    public function provision(Group $group): bool
    {
        $group->loadMissing('cohort');
        $group->status = GroupStatus::Provisioning;
        $group->failed_step = null;
        $group->last_error = null;
        $group->save();

        foreach (ProvisioningStep::cases() as $step) {
            try {
                match ($step) {
                    ProvisioningStep::Team => $this->ensureTeam($group),
                    ProvisioningStep::Repository => $this->ensureRepository($group),
                    ProvisioningStep::RepositoryAccess => $this->ensureRepositoryAccess($group),
                    ProvisioningStep::Project => $this->ensureProject($group),
                    ProvisioningStep::ProjectLinks => $this->ensureProjectLinks($group),
                    ProvisioningStep::ProjectAccess => $this->ensureProjectAccess($group),
                };

                $group->markCompleted($step);
            } catch (Throwable $e) {
                $group->status = GroupStatus::Failed;
                $group->failed_step = $step->value;
                $group->last_error = $e instanceof GitHubException ? $e->reason() : $e->getMessage();
                $group->save();

                Audit::record('group.provisioning_failed', $group, [
                    'step' => $step->value,
                    'error' => $group->last_error,
                ]);

                if ($e instanceof RateLimitedException) {
                    throw $e;
                }

                if (! $e instanceof GitHubException) {
                    report($e);
                }

                return false;
            }
        }

        $group->status = GroupStatus::Provisioned;
        $group->provisioned_at ??= now();
        $group->save();

        return true;
    }

    private function client(): GitHubClient
    {
        return $this->github->org();
    }

    private function org(Group $group): string
    {
        return rawurlencode($group->cohort->organization);
    }

    /*
     * 1. Team
     */

    private function ensureTeam(Group $group): void
    {
        $org = $this->org($group);

        foreach (array_filter([$group->team_slug, $group->githubName()]) as $slug) {
            $response = $this->client()->get("/orgs/{$org}/teams/" . rawurlencode($slug), allow: [404]);

            if ($response->successful()) {
                $this->storeTeam($group, $response->json());

                return;
            }
        }

        $response = $this->client()->post("/orgs/{$org}/teams", [
            'name' => $group->githubName(),
            'description' => "{$group->cohort->name}: {$group->name}",
            'privacy' => config('github.provisioning.team_privacy'),
        ], allow: [422]);

        if ($response->status() === 422) {
            // Created concurrently: use it. Otherwise surface GitHub's reason.
            $existing = $this->client()->get("/orgs/{$org}/teams/" . rawurlencode($group->githubName()), allow: [404]);

            if (! $existing->successful()) {
                throw GitHubException::fromResponse('POST', "/orgs/{$org}/teams", $response);
            }

            $response = $existing;
        } else {
            Audit::record('team.created', $group, ['slug' => $response->json('slug')]);
        }

        $this->storeTeam($group, $response->json());
    }

    private function storeTeam(Group $group, array $team): void
    {
        $group->team_id = $team['id'];
        $group->team_slug = $team['slug'];
        $group->team_node_id = $team['node_id'];
        $group->save();
    }

    /*
     * 2. Private repository (optionally from a template)
     */

    private function ensureRepository(Group $group): void
    {
        $org = $this->org($group);
        $name = $group->repository_name ?? $group->githubName();

        $response = $this->client()->get("/repos/{$org}/" . rawurlencode($name), allow: [404]);

        if ($response->status() === 404) {
            $response = $this->createRepository($group, $name);
        }

        $repository = $response->json();

        if (! ($repository['private'] ?? false)) {
            $repository = $this->client()->patch("/repos/{$org}/" . rawurlencode($name), ['private' => true])->json();

            Audit::record('repository.made_private', $group, ['repository' => $name]);
        }

        $group->repository_id = $repository['id'];
        $group->repository_name = $repository['name'];
        $group->repository_node_id = $repository['node_id'];
        $group->repository_url = $repository['html_url'];
        $group->save();
    }

    private function createRepository(Group $group, string $name)
    {
        $org = $this->org($group);
        $description = "{$group->cohort->name}: {$group->name}";
        $template = $group->cohort->template_repository;

        if ($template) {
            [$owner, $repo] = array_pad(explode('/', $template, 2), 2, '');

            $response = $this->client()->post('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/generate', [
                'owner' => $group->cohort->organization,
                'name' => $name,
                'description' => $description,
                'private' => true,
                'include_all_branches' => false,
            ], allow: [422]);
        } else {
            $response = $this->client()->post("/orgs/{$org}/repos", [
                'name' => $name,
                'description' => $description,
                'private' => true,
                'auto_init' => true,
            ], allow: [422]);
        }

        if ($response->status() === 422) {
            // "name already exists on this account": created concurrently. Use it.
            // Any other validation error: surface GitHub's reason.
            $existing = $this->client()->get("/repos/{$org}/" . rawurlencode($name), allow: [404]);

            if ($existing->successful()) {
                return $existing;
            }

            throw GitHubException::fromResponse('POST', $template ? "/repos/{$template}/generate" : "/orgs/{$org}/repos", $response);
        }

        Audit::record('repository.created', $group, ['repository' => $name, 'template' => $template]);

        return $response;
    }

    /*
     * 3. Team access to the repository (none while the group is locked)
     */

    public function ensureRepositoryAccess(Group $group): void
    {
        $group->loadMissing('cohort');
        $org = $this->org($group);
        $path = "/orgs/{$org}/teams/" . rawurlencode($group->team_slug) . "/repos/{$org}/" . rawurlencode($group->repository_name);

        if ($group->isLocked()) {
            $this->client()->delete($path, allow: [404]);

            return;
        }

        $this->client()->put($path, ['permission' => config('github.provisioning.repository_permission')]);
    }

    /*
     * 4. Org-owned Project (optionally copied from a template project)
     */

    private function ensureProject(Group $group): void
    {
        if ($group->project_node_id) {
            $data = $this->client()->graphql(<<<'GQL'
                query ProjectById($id: ID!) {
                  node(id: $id) { ... on ProjectV2 { id number url closed } }
                }
                GQL, ['id' => $group->project_node_id], allowErrors: true);

            if (! empty($data['node']['id'])) {
                $this->storeProject($group, $data['node']);

                return;
            }
        }

        $title = $group->githubName();

        // Look for a project with exactly this title, to avoid duplicates
        // when a previous attempt created it but failed before saving.
        $data = $this->client()->graphql(<<<'GQL'
            query FindProjects($login: String!, $query: String!) {
              organization(login: $login) {
                projectsV2(first: 50, query: $query) { nodes { id number title url closed } }
              }
            }
            GQL, ['login' => $group->cohort->organization, 'query' => $title]);

        foreach ($data['organization']['projectsV2']['nodes'] ?? [] as $project) {
            if ($project && $project['title'] === $title) {
                $this->storeProject($group, $project);

                return;
            }
        }

        // With Projects disabled for the organization, GitHub only answers
        // "Something went wrong while executing your query". Say what's wrong.
        if (($this->organization($group)['has_organization_projects'] ?? true) === false) {
            throw new GitHubException(self::PROJECTS_DISABLED);
        }

        $ownerId = $this->organization($group)['node_id'];
        $templateNumber = $group->cohort->template_project_number;

        if ($templateNumber) {
            $template = $this->client()->graphql(<<<'GQL'
                query TemplateProject($login: String!, $number: Int!) {
                  organization(login: $login) { projectV2(number: $number) { id } }
                }
                GQL, ['login' => $group->cohort->organization, 'number' => $templateNumber]);

            $templateId = $template['organization']['projectV2']['id'] ?? null;

            if (! $templateId) {
                throw new GitHubException("Template project #{$templateNumber} not found in {$group->cohort->organization}.");
            }

            $data = $this->client()->graphql(<<<'GQL'
                mutation CopyProject($input: CopyProjectV2Input!) {
                  copyProjectV2(input: $input) { projectV2 { id number url } }
                }
                GQL, ['input' => [
                'projectId' => $templateId,
                'ownerId' => $ownerId,
                'title' => $title,
                'includeDraftIssues' => true,
            ]]);

            $project = $data['copyProjectV2']['projectV2'];
        } else {
            $data = $this->client()->graphql(<<<'GQL'
                mutation CreateProject($input: CreateProjectV2Input!) {
                  createProjectV2(input: $input) { projectV2 { id number url } }
                }
                GQL, ['input' => ['ownerId' => $ownerId, 'title' => $title]]);

            $project = $data['createProjectV2']['projectV2'];
        }

        $this->storeProject($group, $project);

        Audit::record('project.created', $group, ['number' => $project['number'], 'template' => $templateNumber]);
    }

    private function storeProject(Group $group, array $project): void
    {
        $group->project_node_id = $project['id'];
        $group->project_number = $project['number'];
        $group->project_url = $project['url'];
        $group->save();
    }

    private function organization(Group $group): array
    {
        return $this->organization ??= $this->client()->get('/orgs/' . $this->org($group))->json();
    }

    /*
     * 5. Link the Project to the repository and the team
     */

    private function ensureProjectLinks(Group $group): void
    {
        $data = $this->client()->graphql(<<<'GQL'
            query ProjectLinks($id: ID!) {
              node(id: $id) {
                ... on ProjectV2 {
                  repositories(first: 100) { nodes { id } }
                  teams(first: 100) { nodes { id } }
                }
              }
            }
            GQL, ['id' => $group->project_node_id]);

        $repositoryIds = array_column($data['node']['repositories']['nodes'] ?? [], 'id');
        $teamIds = array_column($data['node']['teams']['nodes'] ?? [], 'id');

        if (! in_array($group->repository_node_id, $repositoryIds, true)) {
            $this->client()->graphql(<<<'GQL'
                mutation LinkProjectToRepository($input: LinkProjectV2ToRepositoryInput!) {
                  linkProjectV2ToRepository(input: $input) { repository { id } }
                }
                GQL, ['input' => ['projectId' => $group->project_node_id, 'repositoryId' => $group->repository_node_id]]);
        }

        if (! in_array($group->team_node_id, $teamIds, true)) {
            $this->client()->graphql(<<<'GQL'
                mutation LinkProjectToTeam($input: LinkProjectV2ToTeamInput!) {
                  linkProjectV2ToTeam(input: $input) { team { id } }
                }
                GQL, ['input' => ['projectId' => $group->project_node_id, 'teamId' => $group->team_node_id]]);
        }
    }

    /*
     * 6. Team access to the Project (NONE while the group is locked)
     *
     * Linking a project to a team shows it on the team's page, but the docs
     * don't promise write access. Adding the team as collaborator with an
     * explicit role does, and is idempotent (it sets the role).
     *
     * Note: a Project's "base role" (access for all org members) can't be read
     * or changed through the API. If it grants access, locking the team doesn't
     * hide the Project; see the organization-wide Projects switch.
     */

    public function ensureProjectAccess(Group $group): void
    {
        $this->client()->graphql(<<<'GQL'
            mutation SetProjectCollaborators($input: UpdateProjectV2CollaboratorsInput!) {
              updateProjectV2Collaborators(input: $input) { clientMutationId }
            }
            GQL, ['input' => [
            'projectId' => $group->project_node_id,
            'collaborators' => [[
                'teamId' => $group->team_node_id,
                'role' => $group->isLocked() ? 'NONE' : config('github.provisioning.project_role'),
            ]],
        ]]);
    }
}
