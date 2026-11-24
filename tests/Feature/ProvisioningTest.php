<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\GitHub\RateLimitedException;
use App\Jobs\ProvisionCohort;
use App\Models\AuditLog;
use App\Services\Provisioning\GroupProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_group_gets_a_team_private_repository_and_linked_project(): void
    {
        $github = $this->fakeGitHub();

        $cohort = $this->provisionedCohort($github, [
            'alice' => 'Groep 1',
            'bob' => 'Groep 1',
            'carol' => 'Groep 2',
        ]);

        $this->assertCount(2, $github->teams);
        $this->assertCount(2, $github->repos);
        $this->assertCount(2, $github->projects);

        foreach ($cohort->groups as $group) {
            $name = $group->githubName();

            // Team
            $team = $github->teams[$name];
            $this->assertSame($group->team_id, $team['id']);
            $this->assertSame('closed', $team['privacy']);

            // Private repository, accessible only to this team
            $repo = $github->repos[$name];
            $this->assertTrue($repo['private']);
            $this->assertSame(['push'], array_values(array_filter(array_map(fn ($t) => $t['repos'][$name] ?? null, $github->teams))));

            // Project linked to the repository and the team, team has write access
            $project = $github->projects[$group->project_node_id];
            $this->assertSame($name, $project['title']);
            $this->assertSame([$repo['node_id']], $project['repositories']);
            $this->assertSame([$team['node_id']], $project['teams']);
            $this->assertSame([$team['node_id'] => 'WRITER'], $project['collaborators']);

            $this->assertSame(GroupStatus::Provisioned, $group->status);
            $this->assertCount(6, $group->completed_steps);
        }

        $this->assertSame(['sd1a-2026-groep-1', 'sd1a-2026-groep-2'], array_keys($github->repos));
        $this->assertSame(2, AuditLog::where('action', 'repository.created')->count());
    }

    public function test_re_running_provisioning_does_not_create_duplicates(): void
    {
        $github = $this->fakeGitHub();
        $cohort = $this->provisionedCohort($github, ['alice' => 'Groep 1', 'bob' => 'Groep 2']);

        $github->log = [];

        ProvisionCohort::dispatchSync($cohort);
        ProvisionCohort::dispatchSync($cohort);

        $this->assertSame(0, $github->count('POST /orgs/test-org/teams'));
        $this->assertSame(0, $github->count('POST /orgs/test-org/repos'));
        $this->assertSame(0, $github->count('CreateProject'));
        $this->assertSame(0, $github->count('LinkProjectToRepository'));
        $this->assertSame(0, $github->count('LinkProjectToTeam'));
        $this->assertCount(2, $github->teams);
        $this->assertCount(2, $github->repos);
        $this->assertCount(2, $github->projects);
    }

    public function test_a_failed_step_is_reported_and_provisioning_resumes_without_duplicates(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $cohort = $this->makeCohort();
        $this->importRoster($cohort, "github_username,group\nalice,Groep 1\n");

        // Creating the repository fails permanently (all retries).
        $github->failNext('POST /orgs/test-org/repos', 500, ['message' => 'Server Error'], times: 3);

        ProvisionCohort::dispatchSync($cohort);

        $group = $cohort->groups()->first();
        $this->assertSame(GroupStatus::Failed, $group->status);
        $this->assertSame('repository', $group->failed_step);
        $this->assertStringContainsString('500', $group->last_error);
        $this->assertSame(['team'], $group->completed_steps);
        $this->assertCount(1, $github->teams);
        $this->assertCount(0, $github->repos);

        // Retry resumes and completes, reusing the existing team.
        ProvisionCohort::dispatchSync($cohort);

        $group->refresh();
        $this->assertSame(GroupStatus::Provisioned, $group->status);
        $this->assertNull($group->failed_step);
        $this->assertSame(1, $github->count('POST /orgs/test-org/teams'));
        $this->assertCount(1, $github->teams);
        $this->assertCount(1, $github->repos);
        $this->assertCount(1, $github->projects);
    }

    public function test_resources_created_by_an_interrupted_run_are_adopted_instead_of_duplicated(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $cohort = $this->makeCohort();
        $this->importRoster($cohort, "github_username,group\nalice,Groep 1\n");

        // A previous run created everything on GitHub but crashed before saving the IDs.
        ProvisionCohort::dispatchSync($cohort);
        $group = $cohort->groups()->first();
        $group->forceFill([
            'team_id' => null, 'team_slug' => null, 'team_node_id' => null,
            'repository_id' => null, 'repository_name' => null, 'repository_node_id' => null, 'repository_url' => null,
            'project_node_id' => null, 'project_number' => null, 'project_url' => null,
            'status' => 'failed', 'completed_steps' => [],
        ])->save();
        $github->log = [];

        ProvisionCohort::dispatchSync($cohort);

        $group->refresh();
        $this->assertSame(GroupStatus::Provisioned, $group->status);
        $this->assertSame(0, $github->count('CreateProject'));
        $this->assertSame(0, $github->count('LinkProjectToRepository'));
        $this->assertSame(0, $github->count('LinkProjectToTeam'));
        $this->assertSame(0, $github->count('POST /orgs/test-org/(teams|repos)'));
        $this->assertCount(1, $github->projects);
        $this->assertNotNull($group->team_id);
        $this->assertNotNull($group->project_node_id);
    }

    public function test_a_failed_project_creation_is_not_blindly_retried_and_resumes_without_duplicates(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $cohort = $this->makeCohort();
        $this->importRoster($cohort, "github_username,group\nalice,Groep 1\n");
        $github->failNext('CreateProject', 502, ['message' => 'Bad Gateway']);

        ProvisionCohort::dispatchSync($cohort);

        $group = $cohort->groups()->first();
        $this->assertSame('project', $group->failed_step);
        $this->assertSame(1, $github->count('CreateProject'));

        ProvisionCohort::dispatchSync($cohort);

        $this->assertSame(GroupStatus::Provisioned, $group->fresh()->status);
        $this->assertCount(1, $github->projects);
    }

    public function test_templates_are_used_when_configured(): void
    {
        $github = $this->fakeGitHub();
        $github->templateProjects[7] = 'PVT_template';

        $cohort = $this->provisionedCohort($github, ['alice' => 'Groep 1'], [
            'template_repository' => 'test-org/starter',
            'template_project_number' => 7,
        ]);

        $group = $cohort->groups->first();
        $this->assertSame(1, $github->count('POST /repos/test-org/starter/generate'));
        $this->assertSame('test-org/starter', $github->repos[$group->repository_name]['template']);
        $this->assertTrue($github->repos[$group->repository_name]['private']);
        $this->assertSame('PVT_template', $github->projects[$group->project_node_id]['copied_from']);
    }

    public function test_an_existing_public_repository_is_made_private(): void
    {
        $github = $this->fakeGitHub();
        $github->repos['sd1a-2026-groep-1'] = [
            'id' => 1, 'name' => 'sd1a-2026-groep-1', 'node_id' => 'R_1', 'private' => false,
            'archived' => false, 'html_url' => 'https://github.com/test-org/sd1a-2026-groep-1', 'template' => null,
        ];

        $this->provisionedCohort($github, ['alice' => 'Groep 1']);

        $this->assertTrue($github->repos['sd1a-2026-groep-1']['private']);
        $this->assertSame(0, $github->count('POST /orgs/test-org/repos'));
    }

    public function test_secondary_rate_limits_are_retried(): void
    {
        $github = $this->fakeGitHub();
        $github->failNext('POST /orgs/test-org/teams', 403, ['message' => 'You have exceeded a secondary rate limit.'], ['Retry-After' => '2']);

        $this->provisionedCohort($github, ['alice' => 'Groep 1']);

        $this->assertSame(2, $github->count('POST /orgs/test-org/teams'));
        $this->assertCount(1, $github->teams);
    }

    public function test_long_rate_limits_are_thrown_so_the_job_can_be_released(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $cohort = $this->makeCohort();
        $this->importRoster($cohort, "github_username,group\nalice,Groep 1\n");
        $github->failNext('POST /orgs/test-org/teams', 429, ['message' => 'API rate limit exceeded'], ['Retry-After' => '120']);

        $group = $cohort->groups()->first();

        try {
            $this->app->make(GroupProvisioner::class)->provision($group);
            $this->fail('Expected a RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertSame(120, $e->retryAfter);
        }

        $this->assertSame('team', $group->fresh()->failed_step);
    }
}
