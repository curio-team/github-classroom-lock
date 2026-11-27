<?php

namespace App\Services\Offboarding;

use App\Enums\GroupStatus;
use App\GitHub\GitHub;
use App\GitHub\GitHubException;
use App\GitHub\RateLimitedException;
use App\Models\Cohort;
use App\Models\Group;
use App\Services\Audit;

/**
 * After grading: permanently deletes the Projects, repositories and teams of
 * an exam from GitHub. Like the Offboarder, plan() is a dry run and every
 * action is idempotent (something that is already gone counts as deleted),
 * so a partially failed run can simply be executed again.
 */
class GitHubCleanup
{
    public const OPTIONS = [
        'delete_projects' => true,
        'delete_repositories' => true,
        'delete_teams' => true,
    ];

    public function __construct(private readonly GitHub $github)
    {
    }

    public static function normalizeOptions(array $options): array
    {
        $result = [];

        foreach (self::OPTIONS as $key => $default) {
            $result[$key] = (bool) ($options[$key] ?? $default);
        }

        return $result;
    }

    /**
     * What is still on GitHub, for the overview.
     *
     * @return array{projects: int, repositories: int, teams: int}
     */
    public static function remaining(iterable $groups): array
    {
        $counts = ['projects' => 0, 'repositories' => 0, 'teams' => 0];

        foreach ($groups as $group) {
            $counts['projects'] += (int) $group->hasProjectOnGitHub();
            $counts['repositories'] += (int) $group->hasRepositoryOnGitHub();
            $counts['teams'] += (int) $group->hasTeamOnGitHub();
        }

        return $counts;
    }

    /**
     * @return array{groups: array, warnings: array<int, string>, options: array}
     */
    public function plan(Cohort $cohort, array $options): array
    {
        $options = self::normalizeOptions($options);
        $groups = [];
        $warnings = [];

        foreach ($cohort->groups()->get() as $group) {
            $actions = [];

            if ($options['delete_projects'] && $group->hasProjectOnGitHub()) {
                $actions[] = ['type' => 'delete_project', 'label' => "Project #{$group->project_number} verwijderen"];
            }

            if ($options['delete_repositories'] && $group->hasRepositoryOnGitHub()) {
                $actions[] = ['type' => 'delete_repository', 'label' => "Repository {$group->repository_name} verwijderen"];

                if ($group->archived_at === null) {
                    $warnings[] = "Repository {$group->repository_name} is nog niet gearchiveerd.";
                }
            }

            if ($options['delete_teams'] && $group->hasTeamOnGitHub()) {
                $actions[] = ['type' => 'delete_team', 'label' => "Team {$group->team_slug} verwijderen"];
            }

            if ($actions) {
                $groups[] = ['id' => $group->id, 'name' => $group->name, 'actions' => $actions];
            }
        }

        return ['groups' => $groups, 'warnings' => $warnings, 'options' => $options];
    }

    /**
     * @return array{done: int, failed: int}
     *
     * @throws RateLimitedException
     */
    public function execute(Cohort $cohort, array $options): array
    {
        $plan = $this->plan($cohort, $options);
        $done = 0;
        $failed = 0;

        Audit::record('cohort.cleanup_started', $cohort, ['options' => $plan['options']]);

        foreach ($plan['groups'] as $entry) {
            $group = Group::with('cohort')->find($entry['id']);

            if (! $group) {
                continue;
            }

            try {
                foreach ($entry['actions'] as $action) {
                    match ($action['type']) {
                        'delete_project' => $this->deleteProject($group),
                        'delete_repository' => $this->deleteRepository($group),
                        'delete_team' => $this->deleteTeam($group),
                    };
                }

                $group->last_error = null;
                $done++;
            } catch (RateLimitedException $e) {
                throw $e;
            } catch (GitHubException $e) {
                $group->last_error = 'Verwijderen mislukt: ' . $e->reason();
                $failed++;

                Audit::record('group.cleanup_failed', $group, ['error' => $e->reason()]);
            }

            if (! $group->hasProjectOnGitHub() && ! $group->hasRepositoryOnGitHub() && ! $group->hasTeamOnGitHub()) {
                $group->status = GroupStatus::Deleted;
            }

            $group->save();
        }

        Audit::record('cohort.cleanup_finished', $cohort, ['done' => $done, 'failed' => $failed]);

        return ['done' => $done, 'failed' => $failed];
    }

    private function org(Group $group): string
    {
        return rawurlencode($group->cohort->organization);
    }

    private function deleteProject(Group $group): void
    {
        $client = $this->github->org();

        $data = $client->graphql(<<<'GQL'
            query ProjectById($id: ID!) {
              node(id: $id) { ... on ProjectV2 { id number url closed } }
            }
            GQL, ['id' => $group->project_node_id], allowErrors: true);

        // Already gone (deleted by hand, or by a previous attempt that timed out).
        if (! empty($data['node']['id'])) {
            $client->graphql(<<<'GQL'
                mutation DeleteProject($input: DeleteProjectV2Input!) {
                  deleteProjectV2(input: $input) { projectV2 { id } }
                }
                GQL, ['input' => ['projectId' => $group->project_node_id]]);
        }

        $group->project_deleted_at = now();
        $group->save();

        Audit::record('project.deleted', $group, ['number' => $group->project_number]);
    }

    private function deleteRepository(Group $group): void
    {
        $this->github->org()->delete("/repos/{$this->org($group)}/" . rawurlencode($group->repository_name), allow: [404]);

        $group->repository_deleted_at = now();
        $group->save();

        Audit::record('repository.deleted', $group, ['repository' => $group->repository_name]);
    }

    private function deleteTeam(Group $group): void
    {
        $this->github->org()->delete("/orgs/{$this->org($group)}/teams/" . rawurlencode($group->team_slug), allow: [404]);

        $group->team_deleted_at = now();
        $group->save();

        Audit::record('team.deleted', $group, ['team' => $group->team_slug]);
    }
}
