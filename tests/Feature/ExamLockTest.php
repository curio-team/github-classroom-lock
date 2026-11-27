<?php

namespace Tests\Feature;

use App\Jobs\ProvisionCohort;
use App\Livewire\ExamPage;
use App\Models\Group;
use App\Models\Student;
use App\Services\Exam\ExamLock;
use App\Services\Join\JoinService;
use App\Services\Roster\RosterEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeGitHub;
use Tests\TestCase;

class ExamLockTest extends TestCase
{
    use RefreshDatabase;

    private FakeGitHub $github;

    protected function setUp(): void
    {
        parent::setUp();

        $this->github = $this->fakeGitHub();
    }

    /**
     * An exam on its first day: provisioned, everyone joined, groups opened.
     */
    private function examWithJoinedStudents()
    {
        $exam = $this->provisionedCohort($this->github, ['alice' => 'Groep 1', 'bob' => 'Groep 2']);
        $this->app->make(ExamLock::class)->setCohortLocked($exam, false);

        foreach (['alice', 'bob'] as $login) {
            $this->app->make(JoinService::class)->process(Student::where('github_login', $login)->first(), 'user-token-' . $login, $this->github->user($login));
        }

        return $exam->fresh();
    }

    private function projectRole(string $groupName): ?string
    {
        $group = Group::where('name', $groupName)->first();

        return $this->github->projects[$group->project_node_id]['collaborators'][$group->team_node_id] ?? null;
    }

    public function test_groups_are_prepared_locked_and_opened_on_the_day_of_the_exam(): void
    {
        $exam = $this->provisionedCohort($this->github, ['alice' => 'Groep 1', 'bob' => 'Groep 2']);

        // Prepared weeks before: everything exists, but no team has access yet.
        $this->assertSame(2, $exam->groups()->whereNotNull('locked_at')->count());

        foreach (['Groep 1', 'Groep 2'] as $name) {
            $slug = Group::where('name', $name)->first()->githubName();
            $this->assertNull($this->github->teamRepoPermission($slug, $slug));
            $this->assertSame('NONE', $this->projectRole($name));
        }

        // Students can already join; they land in their (still closed) team.
        $this->app->make(JoinService::class)->process(Student::where('github_login', 'alice')->first(), 'user-token-alice', $this->github->user('alice'));
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));
        $this->assertNull($this->github->teamRepoPermission('sd1a-2026-groep-1', 'sd1a-2026-groep-1'));

        // On the day of the exam.
        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])
            ->assertSee('🔒 Vergrendeld')
            ->call('unlockAll')
            ->assertSee('🔓 Open');

        foreach (['Groep 1', 'Groep 2'] as $name) {
            $slug = Group::where('name', $name)->first()->githubName();
            $this->assertSame('push', $this->github->teamRepoPermission($slug, $slug));
            $this->assertSame('WRITER', $this->projectRole($name));
        }
    }

    public function test_a_group_added_while_the_exam_is_open_starts_open(): void
    {
        $exam = $this->examWithJoinedStudents();
        $this->github->addUser('carol');

        $this->app->make(RosterEditor::class)->add($exam, 'carol', 'Groep 3');

        $this->assertFalse(Group::where('name', 'Groep 3')->sole()->isLocked());
    }

    public function test_a_group_added_while_the_exam_is_locked_starts_locked(): void
    {
        $exam = $this->examWithJoinedStudents();
        $this->app->make(ExamLock::class)->setCohortLocked($exam, true);
        $this->github->addUser('carol');

        $this->app->make(RosterEditor::class)->add($exam, 'carol', 'Groep 3');

        $this->assertTrue(Group::where('name', 'Groep 3')->sole()->isLocked());
    }

    public function test_locking_a_group_revokes_its_access_but_keeps_the_members(): void
    {
        $exam = $this->examWithJoinedStudents();
        $groupOne = $exam->groups()->where('name', 'Groep 1')->first();

        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])
            ->assertSee('🔓 Open')
            ->call('lockGroup', $groupOne->id)
            ->assertSee('🔒 Vergrendeld');

        // Group 1: no access to repository and Project, students still in the team.
        $this->assertNull($this->github->teamRepoPermission('sd1a-2026-groep-1', 'sd1a-2026-groep-1'));
        $this->assertSame('NONE', $this->projectRole('Groep 1'));
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));

        // Group 2 is untouched.
        $this->assertSame('push', $this->github->teamRepoPermission('sd1a-2026-groep-2', 'sd1a-2026-groep-2'));
        $this->assertSame('WRITER', $this->projectRole('Groep 2'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'group.lock_requested', 'actor_type' => 'user']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.locked']);
    }

    public function test_lock_all_and_unlock_all(): void
    {
        $exam = $this->examWithJoinedStudents();

        $component = Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])
            ->call('lockAll');

        foreach (['Groep 1', 'Groep 2'] as $name) {
            $this->assertSame('NONE', $this->projectRole($name));
        }
        $this->assertNull($this->github->teamRepoPermission('sd1a-2026-groep-2', 'sd1a-2026-groep-2'));
        $this->assertSame(2, $exam->groups()->whereNotNull('locked_at')->count());

        $component->call('unlockAll');

        foreach (['Groep 1', 'Groep 2'] as $name) {
            $this->assertSame('WRITER', $this->projectRole($name));
        }
        $this->assertSame('push', $this->github->teamRepoPermission('sd1a-2026-groep-1', 'sd1a-2026-groep-1'));
        $this->assertSame(0, $exam->groups()->whereNotNull('locked_at')->count());

        // Members were never removed, so nothing was unassigned.
        $this->assertSame(0, $this->github->count('DELETE /orgs/test-org/teams/.*/memberships/.*'));
    }

    public function test_locking_twice_is_harmless(): void
    {
        $exam = $this->examWithJoinedStudents();
        $component = Livewire::actingAs($this->teacher())->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam']);

        $component->call('lockAll');
        $this->github->log = [];
        $component->call('lockAll')->assertDispatched('notify');

        $this->assertSame([], $this->github->mutations());
    }

    public function test_a_failed_lock_is_shown_and_can_be_retried(): void
    {
        $exam = $this->examWithJoinedStudents();
        $group = $exam->groups()->where('name', 'Groep 1')->first();
        $this->github->failNext('SetProjectCollaborators', 502, ['message' => 'Bad Gateway']);

        $component = Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])
            ->call('lockGroup', $group->id)
            ->assertSee('Vergrendelen mislukt');

        // Lock all retries groups with an error.
        $component->call('lockAll')->assertDontSee('Vergrendelen mislukt');
        $this->assertSame('NONE', $this->projectRole('Groep 1'));
    }

    public function test_re_running_provisioning_keeps_a_locked_group_locked(): void
    {
        $exam = $this->examWithJoinedStudents();
        Livewire::actingAs($this->teacher())->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])->call('lockAll');

        ProvisionCohort::dispatchSync($exam);

        $this->assertNull($this->github->teamRepoPermission('sd1a-2026-groep-1', 'sd1a-2026-groep-1'));
        $this->assertSame('NONE', $this->projectRole('Groep 1'));
    }

    public function test_students_joining_while_locked_are_told_so(): void
    {
        $exam = $this->provisionedCohort($this->github, ['alice' => 'Groep 1']);
        $exam->groups()->update(['locked_at' => now()]);
        $token = $exam->generateInviteLink();

        $redirect = $this->post("/join/{$token}/github");
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->followRedirects($this->get('/join/github/callback?' . http_build_query(['code' => 'code-alice', 'state' => $query['state']])))
            ->assertSee('Je bent toegevoegd!')
            ->assertSee('Je groep is op dit moment vergrendeld');

        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));
    }

    /*
     * Issue assignments: GitHub unassigns people who lose access to a repository.
     */

    private const REPO = 'sd1a-2026-groep-1';

    public function test_issue_assignments_are_saved_when_locking_and_restored_when_unlocking(): void
    {
        $exam = $this->examWithJoinedStudents();
        $group = $exam->groups()->where('name', 'Groep 1')->first();
        $this->github->addUser('docent');
        $this->github->makeOrgMember('docent', 'admin');
        $task = $this->github->addIssue(self::REPO, ['alice', 'docent']);
        $unassigned = $this->github->addIssue(self::REPO);
        $pullRequest = $this->github->addIssue(self::REPO, ['alice']);

        $component = Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])
            ->call('lockGroup', $group->id)
            ->assertSee('2 toewijzingen van issues bewaard');

        // GitHub took alice off; the owner kept access and stays assigned.
        $this->assertSame(['docent'], $this->github->issueAssignees(self::REPO, $task));
        $this->assertSame([], $this->github->issueAssignees(self::REPO, $pullRequest));
        $this->assertSame([$task => ['alice'], $pullRequest => ['alice']], $group->fresh()->assignment_snapshot);

        $component->call('unlockGroup', $group->id)->assertDontSee('van issues bewaard')->assertDontSee('terugzetten');

        $this->assertSame(['docent', 'alice'], $this->github->issueAssignees(self::REPO, $task));
        $this->assertSame([], $this->github->issueAssignees(self::REPO, $unassigned));
        $this->assertSame(['alice'], $this->github->issueAssignees(self::REPO, $pullRequest));
        $this->assertNull($group->fresh()->assignment_snapshot);
        $this->assertNull($group->fresh()->assignment_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.assignments_saved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.assignments_restored']);
    }

    public function test_a_retried_lock_keeps_the_assignments_saved_by_the_first_attempt(): void
    {
        $exam = $this->examWithJoinedStudents();
        $group = $exam->groups()->where('name', 'Groep 1')->first();
        $task = $this->github->addIssue(self::REPO, ['alice']);

        // The repository access is revoked (and alice unassigned), then the Project fails.
        $this->github->failNext('SetProjectCollaborators', 502, ['message' => 'Bad Gateway']);
        $component = Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])
            ->call('lockGroup', $group->id)
            ->assertSee('Vergrendelen mislukt');
        $this->assertSame([], $this->github->issueAssignees(self::REPO, $task));

        // The retry finds nobody assigned, but doesn't forget alice.
        $component->call('lockAll');
        $this->assertSame([$task => ['alice']], $group->fresh()->assignment_snapshot);

        $component->call('unlockAll');
        $this->assertSame(['alice'], $this->github->issueAssignees(self::REPO, $task));
    }

    public function test_assignments_github_removes_late_are_restored_by_the_delayed_check(): void
    {
        $exam = $this->examWithJoinedStudents();
        $group = $exam->groups()->where('name', 'Groep 1')->first();
        $task = $this->github->addIssue(self::REPO, ['alice']);
        $this->github->unassignDelay = 1;

        $lock = $this->app->make(ExamLock::class);
        $lock->setLocked($group, true);

        // Unlocked again before GitHub got round to unassigning: the first run
        // finds everything in place, the delayed check doesn't.
        $lock->setLocked($group->fresh(), false);

        $this->assertSame(['alice'], $this->github->issueAssignees(self::REPO, $task));
        $this->assertSame(1, $this->github->count('POST /repos/test-org/' . self::REPO . '/issues/1/assignees'));
        $this->assertNull($group->fresh()->assignment_snapshot);
    }

    public function test_students_who_left_the_team_are_not_assigned_again(): void
    {
        $exam = $this->examWithJoinedStudents();
        $group = $exam->groups()->where('name', 'Groep 1')->first();
        $task = $this->github->addIssue(self::REPO, ['alice']);
        $lock = $this->app->make(ExamLock::class);

        $lock->setLocked($group, true);
        unset($this->github->teams[self::REPO]['members'][$this->github->user('alice')['id']]);
        $lock->setLocked($group->fresh(), false);

        $this->assertSame([], $this->github->issueAssignees(self::REPO, $task));
        $this->assertSame(0, $this->github->count('POST /repos/.*/assignees'));
        $this->assertNull($group->fresh()->assignment_snapshot);
    }

    public function test_the_lock_goes_ahead_when_the_assignments_cannot_be_saved(): void
    {
        $exam = $this->examWithJoinedStudents();
        $group = $exam->groups()->where('name', 'Groep 1')->first();
        $this->github->failNext('GET /repos/test-org/' . self::REPO . '/issues', 403, ['message' => 'Resource not accessible by integration']);

        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])
            ->call('lockGroup', $group->id)
            ->assertSee('🔒 Vergrendeld')
            ->assertSee('Toewijzingen van issues bewaren mislukt')
            ->assertSee('Heeft de GitHub App de rechten Issues en Pull requests');

        $this->assertNull($this->github->teamRepoPermission(self::REPO, self::REPO));
        $this->assertNull($group->fresh()->lock_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.assignments_save_failed']);
    }

    public function test_assignments_that_cannot_be_restored_are_reported_and_kept(): void
    {
        $exam = $this->examWithJoinedStudents();
        $group = $exam->groups()->where('name', 'Groep 1')->first();
        $task = $this->github->addIssue(self::REPO, ['alice']);
        $component = Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])
            ->call('lockGroup', $group->id);

        $this->github->failNext('POST /repos/.*/assignees', 403, ['message' => 'Resource not accessible by integration'], times: 3);
        $component->call('unlockGroup', $group->id)
            ->assertSee('🔓 Open')
            ->assertSee('Toewijzingen van issues terugzetten mislukt')
            ->assertDontSee('Toewijzingen van issues terugzetten…');

        $this->assertSame([$task => ['alice']], $group->fresh()->assignment_snapshot);
        $this->assertSame(3, $this->github->count('POST /repos/.*/assignees'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.assignments_restore_failed']);

        // The next lock and unlock try again.
        $component->call('lockGroup', $group->id)->call('unlockGroup', $group->id)->assertDontSee('mislukt');
        $this->assertSame(['alice'], $this->github->issueAssignees(self::REPO, $task));
    }

    public function test_the_page_shows_and_polls_groups_that_are_being_updated(): void
    {
        $exam = $this->examWithJoinedStudents();
        $group = $exam->groups()->where('name', 'Groep 1')->first();
        $group->forceFill(['assignment_snapshot' => [1 => ['alice']]])->save();
        $exam->groups()->where('name', 'Groep 2')->update(['lock_pending' => true, 'locked_at' => now()]);

        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])
            ->assertSee('Toewijzingen van issues terugzetten…')
            ->assertSee('Vergrendelen…')
            ->assertSee('2 groepen worden bijgewerkt op GitHub…')
            ->assertSeeHtml('wire:poll.3s')
            // Clicked buttons are replaced by a spinner until the request is done.
            ->assertSeeHtml('wire:target="lockGroup(' . $group->id . ')"')
            ->assertSeeHtml('wire:target="lockAll, unlockAll"');
    }

    public function test_the_organization_wide_projects_switch(): void
    {
        $exam = $this->examWithJoinedStudents();

        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'exam'])
            ->assertSeeText('Nu: aan')
            ->call('setOrganizationProjects', false)
            ->assertSeeText('Nu: uit');

        $this->assertFalse($this->github->organizationProjects);
        $this->assertDatabaseHas('audit_logs', ['action' => 'org.projects_disabled']);
    }
}
