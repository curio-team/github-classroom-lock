<?php

namespace App\Services\Roster;

use App\Enums\GroupStatus;
use App\Enums\StudentStatus;
use App\GitHub\GitHubException;
use App\Models\Cohort;
use App\Models\Group;
use App\Models\Student;
use App\Services\Audit;
use App\Services\GitHubMembership;
use App\Services\GitHubUsers;
use App\Services\Join\JoinService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Changes to the roster one student at a time, for when the list changes
 * after it was imported (a student has to use another GitHub account, or
 * groups are rearranged on the day itself).
 *
 * Unlike a roster import, these changes are applied on GitHub right away:
 * a student who already joined is moved to their new team, and a removed
 * student loses access.
 *
 * Methods return what happened, for the examinator, and throw a
 * RosterException when the change can't be made.
 */
class RosterEditor
{
    private const USERNAME_PATTERN = '/^[a-z\d][a-z\d-]{0,38}$/i';

    public function __construct(
        private readonly GitHubUsers $users,
        private readonly JoinService $joinService,
        private readonly GitHubMembership $membership,
    ) {
    }

    public function add(Cohort $cohort, string $login, string $groupName): RosterChange
    {
        $this->ensureEditable($cohort);

        $login = ltrim(trim($login), '@');

        if (! preg_match(self::USERNAME_PATTERN, $login)) {
            throw new RosterException("'{$login}' is geen geldige GitHub-gebruikersnaam.");
        }

        try {
            $user = $this->users->findByLogin($login);
        } catch (GitHubException $e) {
            throw new RosterException("Kon {$login} niet opzoeken op GitHub: {$e->reason()}");
        }

        if ($user === null) {
            throw new RosterException("Onbekende GitHub-gebruiker: {$login}.");
        }

        if ($user['type'] !== 'User') {
            throw new RosterException("{$login} is geen persoonlijk account ({$user['type']}).");
        }

        $existing = $cohort->students()->with('group')->where('github_user_id', $user['id'])->first();

        if ($existing) {
            throw new RosterException("{$existing->github_login} staat al op de lijst, in groep '{$existing->group?->name}'. Verplaats de student als die in een andere groep moet.");
        }

        [$group, $created] = $this->findOrCreateGroup($cohort, $groupName);

        $student = Student::create([
            'cohort_id' => $cohort->id,
            'group_id' => $group->id,
            'github_user_id' => $user['id'],
            'github_login' => $user['login'],
        ]);

        Audit::record('roster.student_added', $student, ['login' => $student->github_login, 'group' => $group->name]);

        return RosterChange::withNotes(
            "{$student->github_login} is toegevoegd aan {$group->name}. De student kan nu meedoen via de uitnodigingslink.",
            [$this->groupNotReadyNote($group, $created)],
        );
    }

    public function move(Student $student, string $groupName): RosterChange
    {
        $student->loadMissing('cohort', 'group');
        $this->ensureEditable($student->cohort);

        [$group, $created] = $this->findOrCreateGroup($student->cohort, $groupName);
        $from = $student->group;

        if ($from?->id === $group->id) {
            return RosterChange::done("{$student->github_login} zit al in {$group->name}.");
        }

        $wasJoined = $student->status === StudentStatus::Joined;

        if ($wasJoined || $student->status === StudentStatus::Invited) {
            // Remember the old team so the next sync removes them from it.
            $student->previous_group_id ??= $student->group_id;
        }

        if ($wasJoined) {
            $student->status = StudentStatus::Imported;
        }

        $student->group_id = $group->id;
        $student->save();

        Audit::record('roster.student_moved', $student, ['login' => $student->github_login, 'from' => $from?->name, 'to' => $group->name]);

        $this->deleteIfEmpty($from);

        $message = "{$student->github_login} is verplaatst naar {$group->name}.";

        if (! $wasJoined) {
            // Not on GitHub yet (or still has to accept the invitation): they
            // end up in the new team when they join.
            return RosterChange::withNotes($message, [$this->groupNotReadyNote($group, $created)]);
        }

        if (! $group->isReadyForStudents()) {
            $student->markFailed('Verplaatst naar een groep die nog niet is klaargezet op GitHub. De student wordt aan het team toegevoegd zodra de groep klaar is.');

            return RosterChange::attention("{$message} Zet de groep klaar bij stap 2 (Klaarzetten); daarna wordt de student automatisch aan het nieuwe team toegevoegd.");
        }

        $result = $this->joinService->process($student);

        return $result->isJoined()
            ? RosterChange::done($message . ' Het team op GitHub is ook aangepast.')
            : RosterChange::attention($message . " Het team op GitHub is nog niet aangepast: {$result->message}");
    }

    public function remove(Student $student): RosterChange
    {
        $student->loadMissing('cohort', 'group', 'previousGroup');
        $this->ensureEditable($student->cohort);

        // Don't race a join attempt that is running right now.
        $lock = Cache::lock('join-student:' . $student->id, 120);

        if (! $lock->get()) {
            throw new RosterException("Er loopt op dit moment een poging om {$student->github_login} toe te voegen. Probeer het over een paar seconden opnieuw.");
        }

        try {
            $notes = $this->mayHaveAccessOnGitHub($student) ? $this->revokeAccess($student) : [];
        } catch (GitHubException $e) {
            throw new RosterException("Kon de toegang van {$student->github_login} op GitHub niet intrekken: {$e->reason()} De student staat nog op de lijst; probeer het opnieuw.");
        } finally {
            $lock->release();
        }

        $group = $student->group;

        Audit::record('roster.student_removed', $student, ['login' => $student->github_login, 'group' => $group?->name]);

        $student->delete();
        $this->deleteIfEmpty($group);

        return RosterChange::withNotes("{$student->github_login} is verwijderd.", $notes);
    }

    /**
     * @return array<int, string> notes for the examinator about access that was kept
     */
    private function revokeAccess(Student $student): array
    {
        $notes = [];
        $groups = collect([$student->group, $student->previousGroup])->filter()->unique('id');

        foreach ($groups as $group) {
            if ($group->hasTeamOnGitHub()) {
                $this->membership->removeFromTeam($student, $group, 'removed');
            }
        }

        $organization = $student->cohort->organization;

        if ($student->invited_at === null) {
            // Not invited by this app, so they were a member before the exam.
            if ($student->status === StudentStatus::Joined) {
                $notes[] = "De student was al lid van {$organization} vóór dit examen en blijft lid.";
            }

            return $notes;
        }

        if ($others = $this->membership->otherActiveCohorts($student)) {
            $notes[] = "De student zit ook in " . implode(', ', $others) . " en blijft daarom lid van {$organization}.";
        } elseif (! $this->membership->removeFromOrganization($student)) {
            $notes[] = "De student is eigenaar van {$organization} en blijft lid.";
        }

        return $notes;
    }

    private function mayHaveAccessOnGitHub(Student $student): bool
    {
        return in_array($student->status, [StudentStatus::Joined, StudentStatus::Invited], true)
            || $student->invited_at !== null
            || $student->joined_at !== null
            || $student->last_attempt_at !== null;
    }

    /**
     * An existing group by name (or by the name it has on GitHub), or a new one.
     *
     * @return array{0: Group, 1: bool} the group, and whether it was just created
     */
    private function findOrCreateGroup(Cohort $cohort, string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        if ($name === '') {
            throw new RosterException('Kies een groep.');
        }

        if (mb_strlen($name) > 100) {
            throw new RosterException('Een groepsnaam mag maximaal 100 tekens lang zijn.');
        }

        $slug = Str::slug($name);

        if ($slug === '') {
            throw new RosterException("Groepsnaam '{$name}' bevat geen letters of cijfers.");
        }

        $existing = $cohort->groups()->where('name', $name)->first()
            ?? $cohort->groups()->where('slug', $slug)->first();

        if ($existing) {
            if ($existing->archived_at !== null) {
                throw new RosterException("Groep '{$existing->name}' is al gearchiveerd.");
            }

            return [$existing, false];
        }

        $group = Group::create(['cohort_id' => $cohort->id, 'name' => $name, 'slug' => $slug]);

        Audit::record('group.created', $group, ['name' => $name]);

        return [$group, true];
    }

    /**
     * Removes a group that has no students left and nothing on GitHub, like
     * a roster import does.
     */
    private function deleteIfEmpty(?Group $group): void
    {
        if ($group === null || $group->students()->exists()) {
            return;
        }

        if ($group->team_id !== null || $group->repository_id !== null || $group->project_node_id !== null
            || $group->status === GroupStatus::Provisioning) {
            return;
        }

        Audit::record('group.deleted', $group, ['name' => $group->name, 'reason' => 'empty']);

        $group->delete();
    }

    private function groupNotReadyNote(Group $group, bool $created): string
    {
        if ($group->isReadyForStudents()) {
            return '';
        }

        return $created
            ? "Groep {$group->name} is nieuw: zet hem klaar bij stap 2 (Klaarzetten)."
            : "Groep {$group->name} is nog niet klaargezet op GitHub (stap 2).";
    }

    private function ensureEditable(Cohort $cohort): void
    {
        if ($cohort->offboarding_started_at !== null) {
            throw new RosterException('Dit examen is (bijna) afgesloten; de lijst kan niet meer worden aangepast.');
        }
    }
}
