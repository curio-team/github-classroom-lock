<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\StudentStatus;
use App\Jobs\OffboardCohort;
use App\Models\Student;
use App\Services\Join\JoinService;
use App\Services\Offboarding\Offboarder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGitHub;
use Tests\TestCase;

class OffboardingTest extends TestCase
{
    use RefreshDatabase;

    private FakeGitHub $github;

    protected function setUp(): void
    {
        parent::setUp();

        $this->github = $this->fakeGitHub();
    }

    /**
     * alice and bob joined through an invitation; carol was invited but never accepted.
     */
    private function cohortAtEndOfCourse()
    {
        $cohort = $this->provisionedCohort($this->github, ['alice' => 'Groep 1', 'bob' => 'Groep 1', 'carol' => 'Groep 2']);
        $joinService = $this->app->make(JoinService::class);

        foreach (['alice', 'bob'] as $login) {
            $joinService->process(Student::where('github_login', $login)->first(), 'user-token-' . $login, $this->github->user($login));
        }

        $joinService->process(Student::where('github_login', 'carol')->first());

        $this->assertSame(['joined', 'joined', 'invited'], Student::orderBy('github_login')->pluck('status')->map->value->all());
        $this->github->log = [];

        return $cohort->fresh();
    }

    public function test_the_preview_lists_every_change_and_makes_none(): void
    {
        $cohort = $this->cohortAtEndOfCourse();

        $plan = $this->app->make(Offboarder::class)->plan($cohort, Offboarder::OPTIONS);

        $this->assertSame([], $this->github->log);
        $this->assertCount(3, $plan['students']);
        $this->assertCount(2, $plan['groups']);

        $alice = collect($plan['students'])->firstWhere('login', 'alice');
        $this->assertSame(['remove_team', 'remove_org'], array_column($alice['actions'], 'type'));
        $this->assertSame('archive_repository', $plan['groups'][0]['actions'][0]['type']);
        $this->assertSame([], $plan['warnings']);
    }

    public function test_offboarding_removes_access_and_archives_repositories(): void
    {
        $cohort = $this->cohortAtEndOfCourse();

        OffboardCohort::dispatchSync($cohort, Offboarder::OPTIONS);

        foreach (['alice', 'bob', 'carol'] as $login) {
            $this->assertNull($this->github->orgMembershipState($login), "{$login} is still in the org");
            $this->assertNull($this->github->teamMembershipState('sd1a-2026-groep-1', $login));
        }

        $this->assertSame([], $this->github->invitations, 'Pending invitation of carol should be cancelled');

        foreach ($this->github->repos as $repo) {
            $this->assertTrue($repo['archived']);
        }

        $cohort->refresh();
        $this->assertNotNull($cohort->offboarded_at);
        $this->assertSame(['offboarded'], Student::pluck('status')->map->value->unique()->values()->all());
        $this->assertSame([GroupStatus::Archived], $cohort->groups->pluck('status')->unique()->values()->all());

        $this->assertDatabaseHas('audit_logs', ['action' => 'org.member_removed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'repository.archived']);
    }

    public function test_organization_owners_on_the_roster_are_never_removed_from_the_org(): void
    {
        $this->github->addUser('teacher');
        $this->github->makeOrgMember('teacher', role: 'admin');
        $cohort = $this->provisionedCohort($this->github, ['teacher' => 'Groep 1', 'alice' => 'Groep 1']);
        $this->app->make(JoinService::class)->process(Student::where('github_login', 'teacher')->first());

        OffboardCohort::dispatchSync($cohort, Offboarder::OPTIONS);

        $this->assertSame('active', $this->github->orgMembershipState('teacher'));
        $this->assertSame(0, $this->github->count('DELETE /orgs/test-org/memberships/teacher'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'org.member_kept']);
    }

    public function test_students_who_were_already_org_members_are_flagged_in_the_preview(): void
    {
        $this->github->addUser('dave');
        $this->github->makeOrgMember('dave');
        $cohort = $this->provisionedCohort($this->github, ['dave' => 'Groep 1']);
        $this->app->make(JoinService::class)->process(Student::where('github_login', 'dave')->first());

        $plan = $this->app->make(Offboarder::class)->plan($cohort, Offboarder::OPTIONS);

        $this->assertStringContainsString('dave was al lid van test-org vóór dit examen', implode(' ', $plan['warnings']));
    }

    public function test_a_failed_offboarding_can_be_run_again_safely(): void
    {
        $cohort = $this->cohortAtEndOfCourse();
        $this->github->failNext('DELETE /orgs/test-org/memberships/bob', 500, times: 3);

        $result = $this->app->make(Offboarder::class)->execute($cohort, Offboarder::OPTIONS);

        $this->assertSame(1, $result['failed']);
        $this->assertNull($cohort->fresh()->offboarded_at);
        $this->assertSame(StudentStatus::Failed, Student::where('github_login', 'bob')->first()->status);
        $this->assertSame('active', $this->github->orgMembershipState('bob'));

        $result = $this->app->make(Offboarder::class)->execute($cohort->fresh(), Offboarder::OPTIONS);

        $this->assertSame(0, $result['failed']);
        $this->assertNull($this->github->orgMembershipState('bob'));
        $this->assertNotNull($cohort->fresh()->offboarded_at);
    }

    public function test_students_in_another_active_cohort_keep_their_org_membership(): void
    {
        $cohort = $this->cohortAtEndOfCourse();
        $other = $this->makeCohort(['name' => 'Next course', 'slug' => 'next']);
        $this->importRoster($other, "github_username,group\nbob,Team X\n");

        $plan = $this->app->make(Offboarder::class)->plan($cohort, Offboarder::OPTIONS);
        $bob = collect($plan['students'])->firstWhere('login', 'bob');

        $this->assertNotContains('remove_org', array_column($bob['actions'], 'type'));
        $this->assertStringContainsString('bob zit ook in Next course', implode(' ', $plan['warnings']));

        OffboardCohort::dispatchSync($cohort, Offboarder::OPTIONS);

        $this->assertSame('active', $this->github->orgMembershipState('bob'));
        $this->assertNull($this->github->teamMembershipState('sd1a-2026-groep-1', 'bob'));
    }

    public function test_after_offboarding_the_invite_link_cannot_be_used(): void
    {
        $cohort = $this->cohortAtEndOfCourse();
        $token = $cohort->generateInviteLink();

        OffboardCohort::dispatchSync($cohort, Offboarder::OPTIONS);

        $this->get("/join/{$token}")->assertStatus(410)->assertSee('afgesloten');
    }
}
