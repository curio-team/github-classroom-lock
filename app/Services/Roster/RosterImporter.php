<?php

namespace App\Services\Roster;

use App\Enums\StudentStatus;
use App\GitHub\GitHubException;
use App\Models\Cohort;
use App\Models\Group;
use App\Models\Student;
use App\Services\Audit;
use App\Services\GitHubUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two-phase roster import:
 *
 * 1. preview(): parses the file, resolves usernames to numeric IDs and
 *    reports errors and the changes that would be made. Nothing is saved.
 * 2. apply(): saves a preview without errors. Students are matched on their
 *    numeric GitHub ID, so re-importing a corrected roster never creates
 *    duplicates.
 */
class RosterImporter
{
    private const USERNAME_PATTERN = '/^[a-z\d][a-z\d-]{0,38}$/i';

    public function __construct(
        private readonly RosterParser $parser,
        private readonly GitHubUsers $users,
    ) {
    }

    public function preview(Cohort $cohort, string $contents): RosterPreview
    {
        $parsed = $this->parser->parse($contents);
        $errors = $parsed['errors'];
        $warnings = [];
        $valid = [];

        $seenLogins = [];
        $seenIds = [];
        $groupSlugs = [];

        foreach ($parsed['rows'] as $row) {
            $line = $row['line'];
            $login = $row['github_username'];
            $groupName = $row['group'];

            if ($login === '') {
                $errors[] = ['line' => $line, 'message' => 'Lege github_username.'];
                continue;
            }

            if (! preg_match(self::USERNAME_PATTERN, $login)) {
                $errors[] = ['line' => $line, 'message' => "'{$login}' is geen geldige GitHub-gebruikersnaam."];
                continue;
            }

            if ($groupName === '') {
                $errors[] = ['line' => $line, 'message' => "{$login} heeft geen groep (lege groep)."];
                continue;
            }

            $loginKey = strtolower($login);

            if (isset($seenLogins[$loginKey])) {
                $errors[] = ['line' => $line, 'message' => "Dubbele student {$login} (ook op regel {$seenLogins[$loginKey]})."];
                continue;
            }

            $seenLogins[$loginKey] = $line;

            $slug = Str::slug($groupName);

            if ($slug === '') {
                $errors[] = ['line' => $line, 'message' => "Groepsnaam '{$groupName}' bevat geen letters of cijfers."];
                continue;
            }

            if (isset($groupSlugs[$slug]) && $groupSlugs[$slug] !== $groupName) {
                $errors[] = ['line' => $line, 'message' => "Groep '{$groupName}' lijkt te veel op '{$groupSlugs[$slug]}' (zelfde naam op GitHub)."];
                continue;
            }

            $groupSlugs[$slug] = $groupName;

            try {
                $user = $this->users->findByLogin($login);
            } catch (GitHubException $e) {
                $errors[] = ['line' => $line, 'message' => "Kon {$login} niet opzoeken: {$e->getMessage()}"];
                continue;
            }

            if ($user === null) {
                $errors[] = ['line' => $line, 'message' => "Onbekende GitHub-gebruiker: {$login}."];
                continue;
            }

            if ($user['type'] !== 'User') {
                $errors[] = ['line' => $line, 'message' => "{$login} is geen persoonlijk account ({$user['type']})."];
                continue;
            }

            if (isset($seenIds[$user['id']])) {
                $errors[] = ['line' => $line, 'message' => "Dubbele student: {$login} is hetzelfde account als regel {$seenIds[$user['id']]}."];
                continue;
            }

            $seenIds[$user['id']] = $line;

            if (strcasecmp($user['login'], $login) !== 0) {
                $warnings[] = ['line' => $line, 'message' => "{$login} heet nu {$user['login']} op GitHub."];
            }

            $valid[] = [
                'line' => $line,
                'github_user_id' => $user['id'],
                'github_login' => $user['login'],
                'group' => $groupName,
                'group_slug' => $slug,
            ];
        }

        if (! $parsed['errors'] && ! $parsed['rows']) {
            $errors[] = ['line' => null, 'message' => 'Het bestand bevat geen studenten.'];
        }

        return $this->diff($cohort, $valid, $errors, $warnings);
    }

    /**
     * Compares the valid rows with the current roster.
     */
    private function diff(Cohort $cohort, array $rows, array $errors, array $warnings): RosterPreview
    {
        $existingStudents = $cohort->students()->with('group')->get()->keyBy('github_user_id');
        $existingGroups = $cohort->groups()->withCount('students')->get()->keyBy('name');

        $changes = ['new' => [], 'moved' => [], 'unchanged' => [], 'removed' => []];
        $newGroups = [];
        $studentsPerGroup = [];

        foreach ($rows as $row) {
            $studentsPerGroup[$row['group']] = ($studentsPerGroup[$row['group']] ?? 0) + 1;

            if (! $existingGroups->has($row['group'])) {
                $newGroups[$row['group']] = true;

                // A new group whose slug clashes with an existing one.
                $clash = $existingGroups->first(fn (Group $group) => $group->slug === $row['group_slug']);

                if ($clash) {
                    $errors[] = ['line' => $row['line'], 'message' => "Groep '{$row['group']}' lijkt te veel op bestaande groep '{$clash->name}'."];
                }
            }

            /** @var Student|null $existing */
            $existing = $existingStudents->get($row['github_user_id']);

            if ($existing === null) {
                $changes['new'][] = $row;
            } elseif ($existing->group?->name !== $row['group']) {
                $changes['moved'][] = $row + ['from' => $existing->group?->name];

                if (in_array($existing->status, [StudentStatus::Joined, StudentStatus::Invited], true)) {
                    $warnings[] = ['line' => $row['line'], 'message' => "{$row['github_login']} is al toegetreden en wordt verplaatst van '{$existing->group?->name}' naar '{$row['group']}'. Klik daarna op 'Opnieuw proberen' bij de student om de teams op GitHub bij te werken."];
                }
            } else {
                $changes['unchanged'][] = $row;
            }
        }

        $ids = array_column($rows, 'github_user_id');

        foreach ($existingStudents as $student) {
            if (! in_array($student->github_user_id, $ids, true)) {
                $changes['removed'][] = [
                    'github_user_id' => $student->github_user_id,
                    'github_login' => $student->github_login,
                    'group' => $student->group?->name,
                ];

                if (in_array($student->status, [StudentStatus::Joined, StudentStatus::Invited], true)) {
                    $warnings[] = ['line' => null, 'message' => "{$student->github_login} staat niet meer in de roster maar heeft al toegang tot GitHub. Die toegang wordt niet automatisch ingetrokken."];
                }
            }
        }

        // Existing groups that end up without students.
        $emptyGroups = [];

        foreach ($existingGroups as $name => $group) {
            if (($studentsPerGroup[$name] ?? 0) === 0) {
                $emptyGroups[] = [
                    'name' => $name,
                    'provisioned' => $group->team_id !== null || $group->repository_id !== null,
                ];
            }
        }

        foreach ($emptyGroups as $group) {
            $warnings[] = ['line' => null, 'message' => $group['provisioned']
                ? "Groep '{$group['name']}' heeft geen studenten meer. De groep blijft bestaan omdat er al een team/repository op GitHub is."
                : "Groep '{$group['name']}' heeft geen studenten meer en wordt verwijderd."];
        }

        return new RosterPreview(
            cohortId: $cohort->id,
            rows: $rows,
            errors: $errors,
            warnings: $warnings,
            changes: $changes,
            newGroups: array_keys($newGroups),
            emptyGroups: $emptyGroups,
            groupCounts: $studentsPerGroup,
        );
    }

    /**
     * Saves the roster. Refuses previews that contain errors.
     */
    public function apply(Cohort $cohort, RosterPreview $preview): void
    {
        if ($preview->cohortId !== $cohort->id) {
            throw new \InvalidArgumentException('This preview belongs to another cohort.');
        }

        if ($preview->hasErrors()) {
            throw new \InvalidArgumentException('Cannot apply a roster with errors.');
        }

        DB::transaction(function () use ($cohort, $preview) {
            $groups = $cohort->groups()->get()->keyBy('name');

            foreach ($preview->rows as $row) {
                if (! $groups->has($row['group'])) {
                    $groups[$row['group']] = Group::create([
                        'cohort_id' => $cohort->id,
                        'name' => $row['group'],
                        'slug' => $row['group_slug'],
                    ]);
                }
            }

            foreach ($preview->rows as $row) {
                $group = $groups[$row['group']];

                $student = Student::firstOrNew([
                    'cohort_id' => $cohort->id,
                    'github_user_id' => $row['github_user_id'],
                ]);

                $moved = $student->exists && $student->group_id !== $group->id;

                if ($moved && in_array($student->status, [StudentStatus::Joined, StudentStatus::Invited], true)) {
                    // Remember the old team so the next sync can remove them from it.
                    $student->previous_group_id ??= $student->group_id;
                    $student->status = StudentStatus::Imported;
                }

                $student->github_login = $row['github_login'];
                $student->group_id = $group->id;
                $student->save();
            }

            $removedIds = array_column($preview->changes['removed'], 'github_user_id');

            if ($removedIds) {
                Student::where('cohort_id', $cohort->id)->whereIn('github_user_id', $removedIds)->delete();
            }

            foreach ($preview->emptyGroups as $empty) {
                if (! $empty['provisioned']) {
                    $groups->get($empty['name'])?->delete();
                }
            }

            Audit::record('roster.imported', $cohort, [
                'students' => count($preview->rows),
                'new' => count($preview->changes['new']),
                'moved' => count($preview->changes['moved']),
                'removed' => array_column($preview->changes['removed'], 'github_login'),
                'new_groups' => $preview->newGroups,
            ]);
        });
    }
}
