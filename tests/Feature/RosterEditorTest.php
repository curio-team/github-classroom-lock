<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\StudentStatus;
use App\Jobs\ProvisionGroup;
use App\Livewire\ExamPage;
use App\Models\Cohort;
use App\Models\Group;
use App\Models\Student;
use App\Services\Join\JoinService;
use App\Services\Roster\RosterEditor;
use App\Services\Roster\RosterException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeGitHub;
use Tests\TestCase;

/**
 * Adding, moving and removing single students after the roster was imported.
 */
class RosterEditorTest extends TestCase
{
    use RefreshDatabase;

    private FakeGitHub $github;

    protected function setUp(): void
    {
        parent::setUp();

        $this->github = $this->fakeGitHub();
    }

    private function editor(): RosterEditor
    {
        return $this->app->make(RosterEditor::class);
    }

    private function student(string $login): Student
    {
        return Student::where('github_login', $login)->firstOrFail();
    }

    /**
     * A provisioned exam in which the given students have joined through the invite link.
     */
    private function examWithJoined(array $students, array $joined): Cohort
    {
        $cohort = $this->provisionedCohort($this->github, $students);
        $joinService = $this->app->make(JoinService::class);

        foreach ($joined as $login) {
            $joinService->process($this->student($login), 'user-token-' . $login, $this->github->user($login));
            $this->assertSame(StudentStatus::Joined, $this->student($login)->status);
        }

        $this->github->log = [];

        return $cohort->fresh();
    }

    /*
     * Adding
     */

    public function test_a_student_who_cannot_sign_in_is_replaced_by_another_account(): void
    {
        $cohort = $this->examWithJoined(['alice' => 'Groep 1', 'bob' => 'Groep 1'], []);
        $aliceNewId = $this->github->addUser('alice-new');

        $added = $this->editor()->add($cohort, '@alice-new', 'Groep 1');
        $removed = $this->editor()->remove($this->student('alice'));

        $this->assertTrue($added->complete, $added->message);
        $this->assertSame('alice-new is toegevoegd aan Groep 1. De student kan nu meedoen via de uitnodigingslink.', $added->message);
        $this->assertTrue($removed->complete, $removed->message);

        $this->assertSame(['alice-new', 'bob'], $cohort->students()->pluck('github_login')->all());
        $new = $this->student('alice-new');
        $this->assertSame($aliceNewId, $new->github_user_id);
        $this->assertSame('Groep 1', $new->group->name);
        $this->assertSame(StudentStatus::Imported, $new->status);

        // alice never signed in, so there was nothing to undo on GitHub.
        $this->assertSame([], $this->github->mutations());
        $this->assertDatabaseHas('audit_logs', ['action' => 'roster.student_added']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'roster.student_removed']);

        // The new account can join with the existing link.
        $result = $this->app->make(JoinService::class)->process($new, 'user-token-alice-new', $this->github->user('alice-new'));
        $this->assertTrue($result->isJoined());
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-1', 'alice-new'));
    }

    public function test_adding_a_student_is_validated(): void
    {
        $cohort = $this->examWithJoined(['alice' => 'Groep 1'], []);
        $this->github->addUser('some-org', type: 'Organization');
        $this->github->addUser('dave');

        $errors = [];

        foreach ([['not valid!', 'Groep 1'], ['ghost', 'Groep 1'], ['some-org', 'Groep 1'], ['ALICE', 'Groep 2'], ['dave', '  '], ['dave', '!!!']] as [$login, $group]) {
            try {
                $this->editor()->add($cohort, $login, $group);
                $errors[] = null;
            } catch (RosterException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $this->assertSame([
            "'not valid!' is geen geldige GitHub-gebruikersnaam.",
            'Onbekende GitHub-gebruiker: ghost.',
            'some-org is geen persoonlijk account (Organization).',
            "alice staat al op de lijst, in groep 'Groep 1'. Verplaats de student als die in een andere groep moet.",
            'Kies een groep.',
            "Groepsnaam '!!!' bevat geen letters of cijfers.",
        ], $errors);
        $this->assertSame(1, Student::count());
        $this->assertSame(1, Group::count());
    }

    public function test_a_new_group_can_be_created_and_similar_names_reuse_the_existing_group(): void
    {
        $cohort = $this->examWithJoined(['alice' => 'Groep 1'], []);
        $this->github->addUser('bob');
        $this->github->addUser('carol');

        $change = $this->editor()->add($cohort, 'bob', 'Groep 7');
        $this->assertFalse($change->complete);
        $this->assertStringContainsString('Groep Groep 7 is nieuw: zet hem klaar bij stap 2', $change->message);

        $group = Group::where('name', 'Groep 7')->sole();
        $this->assertSame(GroupStatus::Pending, $group->status);
        $this->assertSame('groep-7', $group->slug);

        // "groep  7" is the same group on GitHub, so it is used instead of failing.
        $this->editor()->add($cohort, 'carol', 'groep  7');
        $this->assertSame($group->id, $this->student('carol')->group_id);
        $this->assertSame(2, $cohort->groups()->count());
    }

    /*
     * Moving
     */

    public function test_moving_a_joined_student_updates_the_teams_on_github_right_away(): void
    {
        $cohort = $this->examWithJoined(['alice' => 'Groep 1', 'bob' => 'Groep 2'], ['alice']);

        $change = $this->editor()->move($this->student('alice'), 'Groep 2');

        $this->assertTrue($change->complete, $change->message);
        $this->assertSame('alice is verplaatst naar Groep 2. Het team op GitHub is ook aangepast.', $change->message);
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-2', 'alice'));
        $this->assertNull($this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));
        $this->assertSame('active', $this->github->orgMembershipState('alice'));

        $alice = $this->student('alice');
        $this->assertSame(StudentStatus::Joined, $alice->status);
        $this->assertSame('Groep 2', $alice->group->name);
        $this->assertNull($alice->previous_group_id);

        // Groep 1 has a team on GitHub, so it stays even though it is empty now.
        $this->assertSame(2, $cohort->groups()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'roster.student_moved']);
    }

    public function test_a_joined_student_moved_to_a_new_group_joins_its_team_once_it_is_prepared(): void
    {
        $this->examWithJoined(['alice' => 'Groep 1', 'bob' => 'Groep 1'], ['alice']);

        $change = $this->editor()->move($this->student('alice'), 'Groep 3');

        $this->assertFalse($change->complete);
        $this->assertStringContainsString('Zet de groep klaar bij stap 2', $change->message);
        $alice = $this->student('alice');
        $this->assertSame(StudentStatus::Failed, $alice->status);
        $this->assertNotNull($alice->previous_group_id);

        // Until then, alice keeps working in her old team.
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));

        ProvisionGroup::dispatchSync(Group::where('name', 'Groep 3')->sole());

        $alice->refresh();
        $this->assertSame(StudentStatus::Joined, $alice->status);
        $this->assertNull($alice->previous_group_id);
        $this->assertNull($alice->failure_reason);
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-3', 'alice'));
        $this->assertNull($this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));
    }

    public function test_moving_a_student_who_has_not_joined_only_changes_the_list(): void
    {
        $this->github->addUser('alice');
        $this->github->addUser('bob');
        $cohort = $this->makeCohort();
        $this->importRoster($cohort, "github_username,group\nalice,Groep 1\nbob,Groep 2\n");
        $this->github->log = [];

        $change = $this->editor()->move($this->student('bob'), 'Groep 1');

        $this->assertSame('bob is verplaatst naar Groep 1. Groep Groep 1 is nog niet klaargezet op GitHub (stap 2).', $change->message);
        $this->assertSame([], $this->github->log);
        // Groep 2 is empty and not on GitHub yet, so it is removed (like a roster import does).
        $this->assertSame(['Groep 1'], $cohort->groups()->pluck('name')->all());

        $this->assertSame('alice zit al in Groep 1.', $this->editor()->move($this->student('alice'), 'Groep 1')->message);
    }

    /*
     * Removing
     */

    public function test_removing_a_joined_student_revokes_their_access(): void
    {
        $cohort = $this->examWithJoined(['alice' => 'Groep 1', 'bob' => 'Groep 1'], ['alice']);

        $change = $this->editor()->remove($this->student('alice'));

        $this->assertTrue($change->complete, $change->message);
        $this->assertSame('alice is verwijderd.', $change->message);
        $this->assertNull($this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));
        $this->assertNull($this->github->orgMembershipState('alice'));
        $this->assertSame(['bob'], $cohort->students()->pluck('github_login')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'org.member_removed']);
    }

    public function test_removing_an_invited_student_cancels_the_invitation(): void
    {
        $this->examWithJoined(['alice' => 'Groep 1'], []);
        $this->app->make(JoinService::class)->process($this->student('alice'));
        $this->assertSame('pending', $this->github->orgMembershipState('alice'));

        $this->editor()->remove($this->student('alice'));

        $this->assertNull($this->github->orgMembershipState('alice'));
        $this->assertSame([], $this->github->invitations);
    }

    public function test_existing_members_and_owners_are_taken_out_of_the_team_but_stay_in_the_organization(): void
    {
        $this->github->addUser('teacher');
        $this->github->addUser('owner');
        $this->github->makeOrgMember('teacher');
        $this->examWithJoined(['teacher' => 'Groep 1', 'owner' => 'Groep 1', 'bob' => 'Groep 1'], ['teacher', 'owner']);

        // Became an owner after joining (the app invited them, so it would otherwise remove them).
        $this->github->orgMembers[$this->github->user('owner')['id']]['role'] = 'admin';

        $teacher = $this->editor()->remove($this->student('teacher'));
        $owner = $this->editor()->remove($this->student('owner'));

        $this->assertFalse($teacher->complete);
        $this->assertSame('teacher is verwijderd. De student was al lid van test-org vóór dit examen en blijft lid.', $teacher->message);
        $this->assertSame('owner is verwijderd. De student is eigenaar van test-org en blijft lid.', $owner->message);

        foreach (['teacher', 'owner'] as $login) {
            $this->assertNull($this->github->teamMembershipState('sd1a-2026-groep-1', $login));
            $this->assertSame('active', $this->github->orgMembershipState($login));
        }
    }

    public function test_a_failed_github_call_keeps_the_student_on_the_list(): void
    {
        $this->examWithJoined(['alice' => 'Groep 1'], ['alice']);
        $this->github->failNext('DELETE /orgs/test-org/teams/.+/memberships/alice', 403, ['message' => 'Resource not accessible by integration']);

        try {
            $this->editor()->remove($this->student('alice'));
            $this->fail('Expected a RosterException.');
        } catch (RosterException $e) {
            $this->assertStringContainsString('Kon de toegang van alice op GitHub niet intrekken', $e->getMessage());
        }

        $this->assertSame(1, Student::count());
    }

    public function test_the_list_cannot_be_changed_once_the_exam_is_being_closed(): void
    {
        $cohort = $this->examWithJoined(['alice' => 'Groep 1'], []);
        $cohort->forceFill(['offboarding_started_at' => now()])->save();
        $this->github->addUser('bob');

        foreach ([
            fn () => $this->editor()->add($cohort, 'bob', 'Groep 1'),
            fn () => $this->editor()->move($this->student('alice'), 'Groep 2'),
            fn () => $this->editor()->remove($this->student('alice')),
        ] as $change) {
            try {
                $change();
                $this->fail('Expected a RosterException.');
            } catch (RosterException $e) {
                $this->assertStringContainsString('kan niet meer worden aangepast', $e->getMessage());
            }
        }

        $this->assertSame(['alice'], Student::pluck('github_login')->all());
    }

    /*
     * Exam page
     */

    public function test_the_exam_page_adds_moves_and_removes_students(): void
    {
        $cohort = $this->examWithJoined(['alice' => 'Groep 1', 'bob' => 'Groep 2'], ['alice']);
        $this->github->addUser('carol');

        $page = Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $cohort, 'step' => 'invite'])
            ->assertSee('Student toevoegen')
            ->assertSee('Wijzigen')
            ->assertSeeHtml('<option value="Groep 2"></option>');

        $page->set('newStudentLogin', 'ghost')->set('newStudentGroup', 'Groep 1')
            ->call('addStudent')
            ->assertHasErrors(['newStudentLogin'])
            ->assertSee('Onbekende GitHub-gebruiker: ghost.');

        $page->set('newStudentLogin', 'carol')
            ->call('addStudent')
            ->assertHasNoErrors()
            ->assertSet('newStudentLogin', '')
            ->assertSet('newStudentGroup', 'Groep 1')
            ->assertDispatched('notify', type: 'success');
        $this->assertSame('Groep 1', $this->student('carol')->group->name);

        $page->call('moveStudent', $this->student('alice')->id, 'Groep 2')
            ->assertDispatched('notify', type: 'success', message: 'alice is verplaatst naar Groep 2. Het team op GitHub is ook aangepast.');
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-2', 'alice'));

        $page->call('removeStudent', $this->student('bob')->id)
            ->assertDispatched('notify', type: 'success', message: 'bob is verwijderd.');
        $this->assertSame(['alice', 'carol'], $cohort->students()->pluck('github_login')->all());

        $page->call('moveStudent', $this->student('alice')->id, '')
            ->assertDispatched('notify', type: 'error', message: 'Kies een groep.');

        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $cohort, 'step' => 'students'])
            ->assertSee('Eén student toevoegen')
            ->assertSee('Wijzigen');
    }

    public function test_students_of_another_exam_cannot_be_changed(): void
    {
        $cohort = $this->examWithJoined(['alice' => 'Groep 1'], []);
        $other = $this->makeCohort(['name' => 'Other', 'slug' => 'other']);

        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $other])
            ->call('removeStudent', $this->student('alice')->id)
            ->assertNotFound();

        $this->assertSame(1, $cohort->students()->count());
    }
}
