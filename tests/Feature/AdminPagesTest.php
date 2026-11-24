<?php

namespace Tests\Feature;

use App\Enums\GroupStatus;
use App\Enums\StudentStatus;
use App\Livewire\AuditLogPage;
use App\Livewire\ExamIndexPage;
use App\Livewire\ExamPage;
use App\Models\Cohort;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_and_students_cannot_reach_the_admin_pages(): void
    {
        $this->get('/')->assertOk()->assertSee('Inloggen als examinator');
        $this->get('/teacher/exams')->assertRedirect('/login');

        $student = User::create(['id' => 's1', 'name' => 'Student', 'email' => 's@example.test', 'type' => 'student']);
        $this->actingAs($student)->get('/teacher/exams')->assertForbidden();
        $this->actingAs($student)->get('/dashboard')->assertOk()->assertSee('alleen voor examinatoren');
    }

    public function test_examinators_can_create_an_exam(): void
    {
        $this->fakeGitHub();
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get('/dashboard')->assertRedirect('/teacher/exams');
        $this->actingAs($teacher)->get('/teacher/exams')->assertOk()->assertSee('Nieuw examen');

        Livewire::actingAs($teacher)
            ->test(ExamIndexPage::class)
            ->set('name', 'Examen K1 SD1B')
            ->assertSet('slug', 'examen-k1-sd1b')
            ->set('templateRepository', 'test-org/starter')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $exam = Cohort::sole();
        $this->assertSame('test-org', $exam->organization);
        $this->assertSame('test-org/starter', $exam->template_repository);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cohort.created', 'actor_type' => 'user', 'actor_id' => $teacher->id]);

        Livewire::actingAs($teacher)
            ->test(ExamIndexPage::class)
            ->set('name', 'Duplicate')
            ->set('slug', 'examen-k1-sd1b')
            ->set('templateRepository', 'not a repo')
            ->call('create')
            ->assertHasErrors(['slug', 'templateRepository']);
    }

    public function test_the_exam_list_shows_progress_and_lock_state(): void
    {
        $github = $this->fakeGitHub();
        $exam = $this->provisionedCohort($github, ['alice' => 'Groep 1']);
        $exam->groups()->update(['locked_at' => now()]);

        Livewire::actingAs($this->teacher())
            ->test(ExamIndexPage::class)
            ->assertSee('SD1A 2026')
            ->assertSee('0 / 1 toegevoegd')
            ->assertSee('Vergrendeld');
    }

    public function test_the_page_opens_at_the_step_that_needs_attention(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $exam = $this->makeCohort();
        $teacher = $this->teacher();

        Livewire::actingAs($teacher)->test(ExamPage::class, ['cohort' => $exam])->assertSet('step', 'students');

        $this->importRoster($exam, "github_username,group\nalice,Groep 1\n");
        Livewire::actingAs($teacher)->test(ExamPage::class, ['cohort' => $exam])->assertSet('step', 'prepare');

        \App\Jobs\ProvisionCohort::dispatchSync($exam);
        Livewire::actingAs($teacher)->test(ExamPage::class, ['cohort' => $exam])->assertSet('step', 'invite');

        Student::query()->update(['status' => 'joined']);
        Livewire::actingAs($teacher)->test(ExamPage::class, ['cohort' => $exam])->assertSet('step', 'exam');

        $exam->forceFill(['offboarding_started_at' => now()])->save();
        Livewire::actingAs($teacher)->test(ExamPage::class, ['cohort' => $exam])->assertSet('step', 'finish');
    }

    public function test_student_list_preview_shows_errors_and_blocks_saving(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $exam = $this->makeCohort();

        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'students'])
            ->set('rosterText', "github_username,group\nalice,Groep 1\nghost,Groep 1\n")
            ->call('previewRoster')
            ->assertSee('1 fout(en)')
            ->assertSee('Onbekende GitHub-gebruiker: ghost.')
            ->assertDontSee('>Opslaan<', false)
            ->call('applyRoster');

        $this->assertSame(0, Student::count());
    }

    public function test_student_list_file_upload_preview_and_save(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $github->addUser('bob');
        $exam = $this->makeCohort();

        $file = UploadedFile::fake()->createWithContent('studenten.csv', "github_username;group\nalice;Groep 1\nbob;Groep 2\n");

        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'students'])
            ->set('rosterFile', $file)
            ->call('previewRoster')
            ->assertHasNoErrors()
            ->assertSee('2 geldige studenten')
            ->assertSee('2 nieuw')
            ->call('applyRoster')
            ->assertDispatched('notify')
            ->assertSee('Huidige groepen')
            ->assertSee('alice');

        $this->assertSame(2, Student::count());
        $this->assertSame(['Groep 1', 'Groep 2'], $exam->groups()->pluck('name')->all());
    }

    public function test_preparing_the_groups_on_github(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $exam = $this->makeCohort();
        $this->importRoster($exam, "github_username,group\nalice,Groep 1\n");

        $component = Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'prepare'])
            ->assertSee('Nog niet klaargezet')
            ->call('provisionAll')
            ->assertSee('Klaar')
            ->assertSee('Volgende: uitnodigen');

        $this->assertSame(GroupStatus::Provisioned, $exam->groups()->first()->status);

        // A failure shows its step and reason, and can be retried.
        $github->repos = [];
        $github->failNext('POST /orgs/test-org/repos', 422, ['message' => 'Repository creation failed: quota']);

        $group = $exam->groups()->first();
        $component->call('provisionGroup', $group->id)
            ->assertSee('Mislukt')
            ->assertSee('quota')
            ->call('provisionGroup', $group->id)
            ->assertSee('Klaar');
    }

    public function test_disabled_organization_projects_are_explained_and_can_be_enabled(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $github->organizationProjects = false;
        $exam = $this->makeCohort();
        $this->importRoster($exam, "github_username,group\nalice,amo3a\n");
        $group = $exam->groups()->first();

        $component = Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'prepare'])
            ->assertSee('Zonder Projects kan een groep geen Project krijgen')
            ->call('provisionAll')
            ->assertSee('Mislukt');

        // A clear reason instead of GitHub's "Something went wrong", and no attempt to create.
        $group->refresh();
        $this->assertSame('project', $group->failed_step);
        $this->assertStringStartsWith('Projects staan uit voor de organisatie', $group->last_error);
        $this->assertSame(0, $github->count('CreateProject'));

        $component->call('setOrganizationProjects', true)
            ->assertDontSee('Zonder Projects kan een groep geen Project krijgen')
            ->call('provisionGroup', $group->id)
            ->assertSee('Klaar');

        $this->assertTrue($github->organizationProjects);
        $this->assertCount(1, $github->projects);
        $this->assertCount(1, $github->teams, 'The team from the first attempt is reused');
    }

    public function test_organization_problems_are_shown_with_a_fix(): void
    {
        $github = $this->fakeGitHub();
        $github->basePermission = 'read';
        $exam = $this->makeCohort();

        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'prepare'])
            ->assertSee('Controleer de GitHub-organisatie')
            ->assertSee("Staat op 'read'")
            ->call('fixBasePermission')
            ->call('refreshChecks')
            ->assertDontSee('Controleer de GitHub-organisatie');

        $this->assertSame('none', $github->basePermission);
    }

    public function test_invite_link_can_be_generated_copied_and_revoked(): void
    {
        $this->fakeGitHub();
        $exam = $this->makeCohort();

        $component = Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'invite'])
            ->assertSee('Geen link')
            ->set('inviteExpiresAt', now()->addDays(10)->format('Y-m-d'))
            ->call('generateInviteLink');

        $exam->refresh();
        $this->assertSame('valid', $exam->inviteLinkStatus());
        $component->assertSee($exam->inviteUrl())->assertSee('Kopiëren');

        // The copy button reads the link from the field; no uncompiled Blade may reach the browser.
        $html = $component->html();
        $this->assertStringContainsString('x-ref="link"', $html);
        $this->assertStringContainsString('x-on:click="copy()"', $html);
        $this->assertDoesNotMatchRegularExpression('/@(js|json)\(/', $html);
        $this->assertTrue($exam->invite_expires_at->isSameDay(now()->addDays(10)));

        // The plain token isn't stored; only its hash and an encrypted copy.
        $raw = DB::table('cohorts')->where('id', $exam->id)->first();
        $this->assertNotSame($exam->invite_token, $raw->invite_token);
        $this->assertSame(hash('sha256', $exam->invite_token), $raw->invite_token_hash);
        $this->assertGreaterThanOrEqual(48, strlen($exam->invite_token));

        $component->call('revokeInviteLink')->assertSee('Ingetrokken');
        $this->assertSame('revoked', $exam->fresh()->inviteLinkStatus());

        $component->set('inviteExpiresAt', now()->subDay()->format('Y-m-d'))
            ->call('generateInviteLink')
            ->assertHasErrors('inviteExpiresAt');
    }

    public function test_invite_step_shows_who_is_in_and_allows_retry(): void
    {
        $github = $this->fakeGitHub();
        $exam = $this->provisionedCohort($github, ['alice' => 'Groep 1', 'bob' => 'Groep 1']);
        $github->makeOrgMember('alice');

        $alice = Student::where('github_login', 'alice')->first();
        $alice->markFailed('Something went wrong earlier');
        $alice->forceFill(['last_attempt_at' => now()])->save();

        Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'invite'])
            ->assertSee('Wie is er binnen? (0 / 2)')
            ->assertSee('Mislukt')
            ->assertSee('Something went wrong earlier')
            ->assertSee('Nog niet ingelogd')
            ->call('retryStudent', $alice->id)
            ->assertDispatched('notify')
            ->assertSee('Wie is er binnen? (1 / 2)');

        $this->assertSame(StudentStatus::Joined, $alice->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.retry_requested']);
    }

    public function test_finishing_requires_preview_and_typed_confirmation(): void
    {
        $github = $this->fakeGitHub();
        $exam = $this->provisionedCohort($github, ['alice' => 'Groep 1']);
        $github->makeOrgMember('alice');
        $exam->generateInviteLink();

        $component = Livewire::actingAs($this->teacher())
            ->test(ExamPage::class, ['cohort' => $exam, 'step' => 'finish'])
            ->call('previewOffboarding')
            ->assertSee('Lidmaatschap of openstaande uitnodiging intrekken')
            ->assertSee('Repository sd1a-2026-groep-1 archiveren')
            ->set('offboardConfirmation', 'wrong name')
            ->call('confirmOffboarding')
            ->assertHasErrors('offboardConfirmation');

        $this->assertSame('active', $github->orgMembershipState('alice'));
        $this->assertNull($exam->fresh()->offboarding_started_at);

        $component->set('offboardConfirmation', 'SD1A 2026')->call('confirmOffboarding')->assertHasNoErrors();

        $exam->refresh();
        $this->assertNull($github->orgMembershipState('alice'));
        $this->assertNotNull($exam->offboarded_at);
        $this->assertSame('offboarded', $exam->inviteLinkStatus());
        $this->assertDatabaseHas('audit_logs', ['action' => 'cohort.offboarding_confirmed']);
    }

    public function test_audit_log_page_lists_and_filters_entries(): void
    {
        $github = $this->fakeGitHub();
        $this->provisionedCohort($github, ['alice' => 'Groep 1']);
        $teacher = $this->teacher();

        $this->actingAs($teacher)->get('/teacher/audit-log')->assertOk()->assertSee('team.created');

        Livewire::actingAs($teacher)
            ->test(AuditLogPage::class)
            ->set('search', 'repository.created')
            ->assertSee('repository.created')
            ->assertDontSee('team.created');
    }

    public function test_every_step_renders(): void
    {
        $github = $this->fakeGitHub();
        $exam = $this->provisionedCohort($github, ['alice' => 'Groep 1']);
        $teacher = $this->teacher();

        foreach (['students', 'prepare', 'invite', 'exam', 'finish'] as $step) {
            $this->actingAs($teacher)->get("/teacher/exams/{$exam->id}?step={$step}")->assertOk()->assertSee($exam->name);
        }
    }
}
