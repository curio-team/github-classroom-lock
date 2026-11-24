<?php

namespace App\Livewire;

use App\Enums\GroupStatus;
use App\Enums\StudentStatus;
use App\GitHub\GitHubException;
use App\Jobs\OffboardCohort;
use App\Jobs\ProvisionCohort;
use App\Jobs\ProvisionGroup;
use App\Models\Cohort;
use App\Models\Group;
use App\Services\Audit;
use App\Services\Exam\ExamLock;
use App\Services\Join\JoinService;
use App\Services\Offboarding\Offboarder;
use App\Services\OrganizationChecks;
use App\Services\Roster\RosterImporter;
use App\Services\Roster\RosterPreview;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * One exam, as a sequence of steps:
 * students → prepare (GitHub) → invite → during the exam (lock/unlock) → finish.
 */
class ExamPage extends Component
{
    use WithFileUploads;

    public const STEPS = [
        'students' => 'Studenten',
        'prepare' => 'Klaarzetten',
        'invite' => 'Uitnodigen',
        'exam' => 'Tijdens het examen',
        'finish' => 'Afsluiten',
    ];

    #[Locked]
    public Cohort $cohort;

    #[Url]
    public string $step = '';

    // Students (roster import)
    public $rosterFile = null;

    public string $rosterText = '';

    #[Locked]
    public ?string $previewKey = null;

    // Invite link
    public string $inviteExpiresAt = '';

    // Finish (offboarding)
    public array $offboardOptions = Offboarder::OPTIONS;

    public bool $showOffboardPlan = false;

    public string $offboardConfirmation = '';

    public function mount(Cohort $cohort): void
    {
        $this->cohort = $cohort;
        $this->inviteExpiresAt = ($cohort->invite_expires_at ?? now()->addDays(config('github.limits.invite_link_days')))->format('Y-m-d');

        if (! array_key_exists($this->step, self::STEPS)) {
            $this->step = $this->suggestedStep();
        }
    }

    /**
     * The step that needs attention, used when the page is opened without one.
     */
    private function suggestedStep(): string
    {
        $cohort = $this->cohort;

        if ($cohort->offboarding_started_at) {
            return 'finish';
        }

        if (! $cohort->students()->exists()) {
            return 'students';
        }

        if ($cohort->groups()->where('status', '!=', GroupStatus::Provisioned->value)->whereNull('archived_at')->exists()) {
            return 'prepare';
        }

        $waiting = $cohort->students()->where('status', '!=', StudentStatus::Joined->value)->exists();

        return $waiting ? 'invite' : 'exam';
    }

    /*
     * 1. Students (roster)
     */

    public function previewRoster(RosterImporter $importer): void
    {
        $this->validate([
            'rosterFile' => ['nullable', 'file', 'max:2048', 'mimes:csv,txt,tsv'],
            'rosterText' => ['nullable', 'string', 'max:500000'],
        ]);

        $contents = $this->rosterFile ? file_get_contents($this->rosterFile->getRealPath()) : $this->rosterText;

        if (trim((string) $contents) === '') {
            $this->addError('rosterFile', 'Kies een bestand of plak de lijst in het tekstvak.');

            return;
        }

        RosterPreview::forget($this->previewKey);

        try {
            $this->previewKey = $importer->preview($this->cohort, $contents)->store();
        } catch (Throwable $e) {
            report($e);
            $this->notify('error', 'Controleren mislukt: ' . $e->getMessage());
        }
    }

    public function applyRoster(RosterImporter $importer): void
    {
        $preview = RosterPreview::retrieve($this->previewKey);

        if (! $preview || $preview->hasErrors()) {
            $this->notify('error', 'Er is geen geldige controle om op te slaan. Controleer het bestand opnieuw.');

            return;
        }

        $importer->apply($this->cohort, $preview);

        RosterPreview::forget($this->previewKey);
        $this->reset('previewKey', 'rosterFile', 'rosterText');

        $this->notify('success', count($preview->rows) . ' studenten opgeslagen.');
    }

    public function cancelPreview(): void
    {
        RosterPreview::forget($this->previewKey);
        $this->reset('previewKey');
    }

    /*
     * 2. Prepare (provisioning)
     */

    public function provisionAll(): void
    {
        Audit::record('cohort.provisioning_requested', $this->cohort);

        $this->cohort->groups()->whereNull('archived_at')
            ->update(['status' => GroupStatus::Provisioning->value]);

        ProvisionCohort::dispatch($this->cohort);

        $this->notify('success', 'Bezig met klaarzetten. De status wordt vanzelf bijgewerkt.');
    }

    public function provisionGroup(int $groupId): void
    {
        $group = $this->cohort->groups()->findOrFail($groupId);
        $group->update(['status' => GroupStatus::Provisioning]);

        Audit::record('group.provisioning_requested', $group);

        ProvisionGroup::dispatch($group);
    }

    public function fixBasePermission(OrganizationChecks $checks): void
    {
        try {
            $checks->setBasePermissionToNone();
            $this->notify('success', "Basisrechten staan nu op 'No permission'.");
        } catch (GitHubException $e) {
            $this->notify('error', $e->reason());
        }
    }

    public function refreshChecks(OrganizationChecks $checks): void
    {
        $checks->run(fresh: true);
    }

    /*
     * 3. Invite
     */

    public function generateInviteLink(): void
    {
        $this->validate(['inviteExpiresAt' => ['required', 'date', 'after:today']], [
            'inviteExpiresAt.after' => 'Kies een datum na vandaag.',
        ]);

        $this->cohort->generateInviteLink(Carbon::parse($this->inviteExpiresAt)->endOfDay());

        Audit::record('invite_link.generated', $this->cohort, ['expires_at' => $this->cohort->invite_expires_at->toIso8601String()]);

        $this->notify('success', 'Uitnodigingslink gemaakt. Eerdere links werken niet meer.');
    }

    public function updateInviteExpiry(): void
    {
        $this->validate(['inviteExpiresAt' => ['required', 'date', 'after:today']], [
            'inviteExpiresAt.after' => 'Kies een datum na vandaag.',
        ]);

        $this->cohort->invite_expires_at = Carbon::parse($this->inviteExpiresAt)->endOfDay();
        $this->cohort->save();

        Audit::record('invite_link.expiry_changed', $this->cohort, ['expires_at' => $this->cohort->invite_expires_at->toIso8601String()]);

        $this->notify('success', 'Verloopdatum opgeslagen.');
    }

    public function revokeInviteLink(): void
    {
        $this->cohort->revokeInviteLink();

        Audit::record('invite_link.revoked', $this->cohort);

        $this->notify('success', 'Uitnodigingslink ingetrokken.');
    }

    public function retryStudent(int $studentId, JoinService $joinService): void
    {
        $student = $this->cohort->students()->findOrFail($studentId);

        Audit::record('student.retry_requested', $student);

        $result = $joinService->process($student);

        $this->notify($result->isJoined() ? 'success' : 'warning', "{$student->github_login}: {$result->message}");
    }

    public function retryWaitingStudents(JoinService $joinService): void
    {
        $students = $this->cohort->students()
            ->whereIn('status', [StudentStatus::Failed->value, StudentStatus::Invited->value])
            ->get();

        $joined = 0;

        foreach ($students as $student) {
            if ($joinService->process($student)->isJoined()) {
                $joined++;
            }
        }

        $this->notify('success', "{$students->count()} studenten opnieuw gecontroleerd, {$joined} zijn nu toegevoegd.");
    }

    /*
     * 4. During the exam (lock/unlock)
     */

    public function lockGroup(int $groupId, ExamLock $lock): void
    {
        $lock->setLocked($this->cohort->groups()->findOrFail($groupId), true);
    }

    public function unlockGroup(int $groupId, ExamLock $lock): void
    {
        $lock->setLocked($this->cohort->groups()->findOrFail($groupId), false);
    }

    public function lockAll(ExamLock $lock): void
    {
        $count = $lock->setCohortLocked($this->cohort, true);
        $this->notify('success', $count ? "{$count} groepen worden vergrendeld." : 'Alle groepen waren al vergrendeld.');
    }

    public function unlockAll(ExamLock $lock): void
    {
        $count = $lock->setCohortLocked($this->cohort, false);
        $this->notify('success', $count ? "{$count} groepen worden ontgrendeld." : 'Alle groepen waren al ontgrendeld.');
    }

    public function setOrganizationProjects(bool $enabled, ExamLock $lock): void
    {
        try {
            $lock->setOrganizationProjects($enabled);
            $this->notify('success', $enabled ? 'Projects staan weer aan voor de hele organisatie.' : 'Projects staan uit voor de hele organisatie.');
        } catch (GitHubException $e) {
            $this->notify('error', $e->reason());
        }
    }

    /*
     * 5. Finish (offboarding)
     */

    public function previewOffboarding(): void
    {
        $this->showOffboardPlan = true;
        $this->offboardConfirmation = '';
    }

    public function confirmOffboarding(): void
    {
        $this->resetErrorBag('offboardConfirmation');

        if (trim($this->offboardConfirmation) !== $this->cohort->name) {
            $this->addError('offboardConfirmation', 'Typ de naam van het examen precies over om te bevestigen.');

            return;
        }

        $options = Offboarder::normalizeOptions($this->offboardOptions);

        Audit::record('cohort.offboarding_confirmed', $this->cohort, ['options' => $options]);

        // Nobody can join while the exam is being closed.
        if ($this->cohort->inviteLinkIsUsable()) {
            $this->cohort->revokeInviteLink();
        }

        $this->cohort->offboarding_started_at ??= now();
        $this->cohort->save();

        OffboardCohort::dispatch($this->cohort, $options);

        $this->showOffboardPlan = false;
        $this->offboardConfirmation = '';
        $this->notify('success', 'Het examen wordt afgesloten.');
    }

    private function notify(string $type, string $message): void
    {
        $this->dispatch('notify', type: $type, message: $message);
    }

    public function render(OrganizationChecks $checks, Offboarder $offboarder, ExamLock $lock)
    {
        $this->cohort->refresh();

        $groups = $this->cohort->groups()->with('students')->withCount('students')->get();
        $students = $this->cohort->students()->with('group')->get();
        $statusCounts = $this->cohort->studentStatusCounts();
        $activeGroups = $groups->whereNull('archived_at');

        $orgChecks = [];

        if (in_array($this->step, ['prepare', 'invite'], true)) {
            try {
                $orgChecks = $checks->run();
            } catch (Throwable $e) {
                $orgChecks = [['key' => 'access', 'label' => 'GitHub', 'level' => 'error', 'message' => $e->getMessage()]];
            }
        }

        $organizationProjects = null;

        if (in_array($this->step, ['prepare', 'exam'], true)) {
            try {
                $organizationProjects = $lock->organizationProjectsEnabled();
            } catch (Throwable) {
                $organizationProjects = null;
            }
        }

        $provisioned = $activeGroups->where('status', GroupStatus::Provisioned)->count();
        $lockedCount = $activeGroups->filter->isLocked()->count();

        return view('livewire.exam-page', [
            'steps' => self::STEPS,
            'progress' => [
                'students' => $students->count() > 0,
                'prepare' => $activeGroups->isNotEmpty() && $provisioned === $activeGroups->count(),
                'invite' => $students->isNotEmpty() && $statusCounts['joined'] === $students->count(),
                'exam' => false,
                'finish' => $this->cohort->offboarded_at !== null,
            ],
            'groups' => $groups,
            'students' => $students,
            'statusCounts' => $statusCounts,
            'provisionedCount' => $provisioned,
            'lockedCount' => $lockedCount,
            'activeGroupCount' => $activeGroups->count(),
            'preview' => RosterPreview::retrieve($this->previewKey),
            'orgChecks' => $orgChecks,
            'orgProblems' => array_values(array_filter($orgChecks, fn ($check) => in_array($check['level'], ['error', 'warning'], true))),
            'organizationProjects' => $organizationProjects,
            'offboardPlan' => $this->showOffboardPlan ? $offboarder->plan($this->cohort, $this->offboardOptions) : null,
            'isBusy' => $groups->contains(fn (Group $group) => $group->status === GroupStatus::Provisioning || $group->lock_pending)
                || ($this->cohort->offboarding_started_at !== null && $this->cohort->offboarded_at === null),
        ]);
    }
}
