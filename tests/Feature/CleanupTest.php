<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Jobs\CleanUpCohort;
use App\Jobs\OffboardCohort;
use App\Livewire\ExamPage;
use App\Models\Cohort;
use App\Services\Offboarding\GitHubCleanup;
use App\Services\Offboarding\Offboarder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeGitHub;
use Tests\TestCase;

class CleanupTest extends TestCase
{
    use RefreshDatabase;

    private FakeGitHub $github;

    protected function setUp(): void
    {
        parent::setUp();

        $this->github = $this->fakeGitHub();
    }

    private function offboardedCohort(): Cohort
    {
        $cohort = $this->provisionedCohort($this->github, ['alice' => 'Groep 1', 'bob' => 'Groep 2']);

        OffboardCohort::dispatchSync($cohort, Offboarder::OPTIONS);

        $this->assertNotNull($cohort->fresh()->offboarded_at);
        $this->github->log = [];

        return $cohort->fresh();
    }

    public function test_the_preview_lists_every_deletion_and_makes_none(): void
    {
        $cohort = $this->offboardedCohort();

        $plan = $this->app->make(GitHubCleanup::class)->plan($cohort, GitHubCleanup::OPTIONS);

        $this->assertSame([], $this->github->log);
        $this->assertCount(2, $plan['groups']);
        $this->assertSame(['delete_project', 'delete_repository', 'delete_team'], array_column($plan['groups'][0]['actions'], 'type'));
        $this->assertSame([], $plan['warnings']);
    }

    public function test_cleanup_deletes_projects_repositories_and_teams(): void
    {
        $cohort = $this->offboardedCohort();

        CleanUpCohort::dispatchSync($cohort, GitHubCleanup::OPTIONS);

        $this->assertSame([], $this->github->projects);
        $this->assertSame([], $this->github->repos);
        $this->assertSame([], $this->github->teams);

        foreach ($cohort->groups()->get() as $group) {
            $this->assertSame(GroupStatus::Deleted, $group->status);
            $this->assertNotNull($group->repository_deleted_at);
            $this->assertNotNull($group->team_deleted_at);
            $this->assertNotNull($group->project_deleted_at);
            // Kept for the audit trail.
            $this->assertNotNull($group->repository_name);
        }

        $this->assertFalse($cohort->fresh()->cleanup_pending);
        $this->assertDatabaseHas('audit_logs', ['action' => 'repository.deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'team.deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.deleted']);

        // Running it again has nothing left to do.
        $this->github->log = [];
        $this->assertSame([], $this->app->make(GitHubCleanup::class)->plan($cohort, GitHubCleanup::OPTIONS)['groups']);
        CleanUpCohort::dispatchSync($cohort, GitHubCleanup::OPTIONS);
        $this->assertSame([], $this->github->mutations());
    }

    public function test_only_the_chosen_resources_are_deleted(): void
    {
        $cohort = $this->offboardedCohort();

        CleanUpCohort::dispatchSync($cohort, ['delete_projects' => true, 'delete_repositories' => false, 'delete_teams' => false]);

        $this->assertSame([], $this->github->projects);
        $this->assertCount(2, $this->github->repos);
        $this->assertCount(2, $this->github->teams);
        $this->assertSame([GroupStatus::Archived], $cohort->groups()->get()->pluck('status')->unique()->values()->all());
        $this->assertSame(['projects' => 0, 'repositories' => 2, 'teams' => 2], GitHubCleanup::remaining($cohort->groups()->get()));
    }

    public function test_resources_already_deleted_by_hand_count_as_deleted(): void
    {
        $cohort = $this->offboardedCohort();
        $this->github->repos = [];
        $this->github->teams = [];
        $this->github->projects = [];

        $result = $this->app->make(GitHubCleanup::class)->execute($cohort, GitHubCleanup::OPTIONS);

        $this->assertSame(['done' => 2, 'failed' => 0], $result);
        $this->assertSame(0, $this->github->count('DeleteProject'), 'A project that is already gone is not deleted again');
        $this->assertSame([GroupStatus::Deleted], $cohort->groups()->get()->pluck('status')->unique()->values()->all());
    }

    public function test_a_failed_cleanup_can_be_run_again_safely(): void
    {
        $cohort = $this->offboardedCohort();
        $this->github->failNext('DELETE /repos/test-org/sd1a-2026-groep-1', 403, ['message' => 'Must have admin rights to Repository.']);

        $result = $this->app->make(GitHubCleanup::class)->execute($cohort, GitHubCleanup::OPTIONS);

        $this->assertSame(['done' => 1, 'failed' => 1], $result);
        $group = $cohort->groups()->where('name', 'Groep 1')->first();
        $this->assertStringContainsString('Must have admin rights', $group->last_error);
        $this->assertNotNull($group->project_deleted_at);
        $this->assertNull($group->repository_deleted_at);
        $this->assertArrayHasKey('sd1a-2026-groep-1', $this->github->repos);
        $this->assertArrayHasKey('sd1a-2026-groep-1', $this->github->teams, 'The team is kept when the repository could not be deleted');

        $result = $this->app->make(GitHubCleanup::class)->execute($cohort, GitHubCleanup::OPTIONS);

        $this->assertSame(['done' => 1, 'failed' => 0], $result);
        $this->assertSame([], $this->github->repos);
        $this->assertNull($group->fresh()->last_error);
    }

    public function test_unarchived_repositories_are_flagged_in_the_preview(): void
    {
        $cohort = $this->provisionedCohort($this->github, ['alice' => 'Groep 1']);

        $plan = $this->app->make(GitHubCleanup::class)->plan($cohort, GitHubCleanup::OPTIONS);

        $this->assertStringContainsString('sd1a-2026-groep-1 is nog niet gearchiveerd', implode(' ', $plan['warnings']));
    }

    public function test_cleanup_requires_a_closed_exam_preview_and_typed_confirmation(): void
    {
        $cohort = $this->provisionedCohort($this->github, ['alice' => 'Groep 1']);
        $teacher = $this->teacher();

        // Not available while the exam is still running.
        Livewire::actingAs($teacher)
            ->test(ExamPage::class, ['cohort' => $cohort, 'step' => 'finish'])
            ->assertSee('Sluit eerst het examen af')
            ->assertDontSee('Bekijk wat er verwijderd wordt')
            ->set('cleanupConfirmation', 'SD1A 2026')
            ->call('confirmCleanup');

        $this->assertCount(1, $this->github->repos);

        OffboardCohort::dispatchSync($cohort, Offboarder::OPTIONS);

        Livewire::actingAs($teacher)
            ->test(ExamPage::class, ['cohort' => $cohort->fresh(), 'step' => 'finish'])
            ->assertSee('Nog op GitHub: 1 repositories, 1 Projects en 1 teams')
            ->call('previewCleanup')
            ->assertSee('Repository sd1a-2026-groep-1 verwijderen')
            ->assertSee('Team sd1a-2026-groep-1 verwijderen')
            ->set('cleanupConfirmation', 'wrong name')
            ->call('confirmCleanup')
            ->assertHasErrors('cleanupConfirmation')
            ->set('cleanupConfirmation', 'SD1A 2026')
            ->call('confirmCleanup')
            ->assertHasNoErrors()
            ->assertSee('Alles is van GitHub verwijderd');

        $this->assertSame([], $this->github->repos);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cohort.cleanup_confirmed']);

        // Links to deleted resources are gone from the overview.
        Livewire::actingAs($teacher)
            ->test(ExamPage::class, ['cohort' => $cohort->fresh(), 'step' => 'prepare'])
            ->assertSee('Verwijderd van GitHub')
            ->assertDontSee('https://github.com/test-org/sd1a-2026-groep-1');
    }
}
