<?php

namespace Tests\Feature;

use App\Enums\StudentStatus;
use App\Models\Group;
use App\Models\Student;
use App\Services\Roster\RosterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RosterImportTest extends TestCase
{
    use RefreshDatabase;

    private function importer(): RosterImporter
    {
        return $this->app->make(RosterImporter::class);
    }

    private function messages(array $issues): array
    {
        return array_column($issues, 'message');
    }

    public function test_usernames_are_resolved_to_numeric_ids(): void
    {
        $github = $this->fakeGitHub();
        $aliceId = $github->addUser('Alice');
        $cohort = $this->makeCohort();

        $this->importRoster($cohort, "github_username,group\nalice,Groep 1\n");

        $student = Student::sole();
        $this->assertSame($aliceId, $student->github_user_id);
        $this->assertSame('Alice', $student->github_login); // canonical casing from GitHub
        $this->assertSame('Groep 1', $student->group->name);
        $this->assertSame(StudentStatus::Imported, $student->status);
    }

    public function test_preview_reports_errors_before_anything_is_saved(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $github->addUser('bob');
        $github->addUser('some-org', type: 'Organization');

        $preview = $this->importer()->preview($this->makeCohort(), implode("\n", [
            'github_username,group',
            'alice,Groep 1',
            'ghost-user,Groep 1',     // unknown on GitHub
            'ALICE,Groep 2',          // duplicate student
            'bob,',                   // empty group
            'not valid!,Groep 1',     // invalid username
            'some-org,Groep 1',       // not a user account
            '',
        ]));

        $this->assertTrue($preview->hasErrors());
        $messages = $this->messages($preview->errors);
        $this->assertContains('Onbekende GitHub-gebruiker: ghost-user.', $messages);
        $this->assertContains('Dubbele student ALICE (ook op regel 2).', $messages);
        $this->assertContains('bob heeft geen groep (lege groep).', $messages);
        $this->assertContains("'not valid!' is geen geldige GitHub-gebruikersnaam.", $messages);
        $this->assertContains('some-org is geen persoonlijk account (Organization).', $messages);

        // Errors point at the offending line of the file.
        $this->assertSame([3, 4, 5, 6, 7], array_column($preview->errors, 'line'));
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Group::count());

        $this->expectException(\InvalidArgumentException::class);
        $this->importer()->apply($this->makeCohort(['slug' => 'other']), $preview);
    }

    public function test_renamed_account_listed_twice_is_a_duplicate(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');

        // "old-alice" redirects to the same account on GitHub.
        $github->users['old-alice'] = $github->users['alice'];

        $preview = $this->importer()->preview($this->makeCohort(), "github_username,group\nalice,A\nold-alice,B\n");

        $this->assertContains('Dubbele student: old-alice is hetzelfde account als regel 2.', $this->messages($preview->errors));
    }

    public function test_re_importing_a_corrected_roster_does_not_create_duplicates(): void
    {
        $github = $this->fakeGitHub();
        foreach (['alice', 'bob', 'carol', 'dave'] as $login) {
            $github->addUser($login);
        }
        $cohort = $this->makeCohort();

        $this->importRoster($cohort, "github_username,group\nalice,Groep 1\nbob,Groep 1\ncarol,Groep 2\n");
        $this->assertSame(3, Student::count());
        $groupOne = Group::where('name', 'Groep 1')->first();

        // Correction: bob moves, carol is removed (Groep 2 becomes empty), dave is new.
        $preview = $this->importer()->preview($cohort, "github_username,group\nalice,Groep 1\nbob,Groep 3\ndave,Groep 1\n");

        $this->assertFalse($preview->hasErrors());
        $this->assertSame(['dave'], array_column($preview->changes['new'], 'github_login'));
        $this->assertSame(['bob'], array_column($preview->changes['moved'], 'github_login'));
        $this->assertSame(['carol'], array_column($preview->changes['removed'], 'github_login'));
        $this->assertSame(['Groep 3'], $preview->newGroups);
        $this->assertSame([['name' => 'Groep 2', 'provisioned' => false]], $preview->emptyGroups);
        $this->assertStringContainsString("Groep 'Groep 2' heeft geen studenten meer", implode(' ', $this->messages($preview->warnings)));

        $this->importer()->apply($cohort, $preview);

        $this->assertSame(3, Student::count());
        $this->assertSame(['alice', 'bob', 'dave'], Student::orderBy('github_login')->pluck('github_login')->all());
        $this->assertSame('Groep 3', Student::where('github_login', 'bob')->first()->group->name);
        $this->assertSame(['Groep 1', 'Groep 3'], $cohort->groups()->pluck('name')->all());
        $this->assertSame($groupOne->id, Group::where('name', 'Groep 1')->first()->id);

        // Importing the same file again changes nothing.
        $again = $this->importer()->preview($cohort, "github_username,group\nalice,Groep 1\nbob,Groep 3\ndave,Groep 1\n");
        $this->assertCount(3, $again->changes['unchanged']);
        $this->assertSame([], $again->changes['new']);
    }

    public function test_provisioned_empty_groups_are_kept_and_joined_students_moving_are_flagged(): void
    {
        $github = $this->fakeGitHub();
        $cohort = $this->provisionedCohort($github, ['alice' => 'Groep 1', 'bob' => 'Groep 2']);
        Student::where('github_login', 'bob')->update(['status' => 'joined']);

        $preview = $this->importer()->preview($cohort, "github_username,group\nalice,Groep 1\nbob,Groep 1\n");

        $this->assertSame([['name' => 'Groep 2', 'provisioned' => true]], $preview->emptyGroups);
        $this->assertStringContainsString('bob is al toegetreden en wordt verplaatst', implode(' ', $this->messages($preview->warnings)));

        $this->importer()->apply($cohort, $preview);

        $bob = Student::where('github_login', 'bob')->first();
        $this->assertSame('Groep 1', $bob->group->name);
        $this->assertSame('Groep 2', $bob->previousGroup->name);
        $this->assertSame(StudentStatus::Imported, $bob->status);
        $this->assertSame(2, $cohort->groups()->count());
    }

    public function test_group_names_that_collide_on_github_are_rejected(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $github->addUser('bob');

        $preview = $this->importer()->preview($this->makeCohort(), "github_username,group\nalice,Groep 1\nbob,groep-1\n");

        $this->assertStringContainsString("lijkt te veel op 'Groep 1'", implode(' ', $this->messages($preview->errors)));
    }
}
