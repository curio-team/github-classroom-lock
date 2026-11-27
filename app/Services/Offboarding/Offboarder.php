<?php

namespace App\Services\Offboarding;

use App\Enums\GroupStatus;
use App\Enums\StudentStatus;
use App\GitHub\GitHub;
use App\GitHub\GitHubException;
use App\GitHub\RateLimitedException;
use App\Models\Cohort;
use App\Models\Group;
use App\Models\Student;
use App\Services\Audit;
use App\Services\GitHubMembership;

/**
 * End-of-course offboarding. plan() is a dry run that lists every change;
 * execute() performs it. Each action is idempotent (a 404 means "already
 * done"), so a partially failed run can simply be executed again.
 */
class Offboarder
{
    public const OPTIONS = [
        'remove_team_memberships' => true,
        'remove_org_membership' => true,
        'archive_repositories' => true,
    ];

    public function __construct(
        private readonly GitHub $github,
        private readonly GitHubMembership $membership,
    ) {
    }

    public static function normalizeOptions(array $options): array
    {
        $result = [];

        foreach (self::OPTIONS as $key => $default) {
            $result[$key] = (bool) ($options[$key] ?? $default);
        }

        return $result;
    }

    /**
     * @return array{students: array, groups: array, warnings: array<int, string>, options: array}
     */
    public function plan(Cohort $cohort, array $options): array
    {
        $options = self::normalizeOptions($options);
        $warnings = [];
        $students = [];
        $groups = [];

        $cohort->loadMissing('students.group', 'groups');

        foreach ($cohort->students as $student) {
            if ($student->status === StudentStatus::Offboarded) {
                continue;
            }

            $actions = [];

            if ($options['remove_team_memberships'] && $student->group?->hasTeamOnGitHub()) {
                $actions[] = ['type' => 'remove_team', 'label' => "Verwijderen uit team {$student->group->team_slug}"];
            }

            if ($options['remove_org_membership']) {
                $otherCohorts = $this->membership->otherActiveCohorts($student);

                if ($otherCohorts) {
                    $warnings[] = "{$student->github_login} zit ook in " . implode(', ', $otherCohorts) . ' en blijft daarom lid van de organisatie.';
                } else {
                    $actions[] = ['type' => 'remove_org', 'label' => $student->status === StudentStatus::Joined
                        ? "Lidmaatschap van {$cohort->organization} intrekken"
                        : 'Lidmaatschap of openstaande uitnodiging intrekken (indien aanwezig)'];

                    if ($student->status === StudentStatus::Joined && $student->invited_at === null) {
                        $warnings[] = "{$student->github_login} was al lid van {$cohort->organization} vóór dit examen, en wordt nu ook uit de organisatie verwijderd.";
                    }
                }
            }

            if ($actions) {
                $students[] = ['id' => $student->id, 'login' => $student->github_login, 'status' => $student->status->value, 'actions' => $actions];
            }
        }

        if ($options['archive_repositories']) {
            foreach ($cohort->groups as $group) {
                if ($group->hasRepositoryOnGitHub() && $group->archived_at === null) {
                    $groups[] = ['id' => $group->id, 'name' => $group->name, 'actions' => [
                        ['type' => 'archive_repository', 'label' => "Repository {$group->repository_name} archiveren"],
                    ]];
                }
            }
        }

        return ['students' => $students, 'groups' => $groups, 'warnings' => $warnings, 'options' => $options];
    }

    /**
     * @return array{done: int, failed: int}
     *
     * @throws RateLimitedException
     */
    public function execute(Cohort $cohort, array $options): array
    {
        $plan = $this->plan($cohort, $options);
        $done = 0;
        $failed = 0;

        if ($cohort->offboarding_started_at === null) {
            $cohort->offboarding_started_at = now();
            $cohort->save();

            Audit::record('cohort.offboarding_started', $cohort, ['options' => $plan['options']]);
        }

        foreach ($plan['students'] as $entry) {
            $student = Student::with('group', 'cohort')->find($entry['id']);

            if (! $student) {
                continue;
            }

            try {
                foreach ($entry['actions'] as $action) {
                    $this->performStudentAction($student, $action['type']);
                }

                $student->status = StudentStatus::Offboarded;
                $student->offboarded_at = now();
                $student->failure_reason = null;
                $student->save();
                $done++;
            } catch (RateLimitedException $e) {
                throw $e;
            } catch (GitHubException $e) {
                $student->markFailed('Offboarding: ' . $e->reason());
                Audit::record('student.offboarding_failed', $student, ['error' => $e->reason()]);
                $failed++;
            }
        }

        foreach ($plan['groups'] as $entry) {
            $group = Group::with('cohort')->find($entry['id']);

            try {
                $this->archiveRepository($group);
                $done++;
            } catch (RateLimitedException $e) {
                throw $e;
            } catch (GitHubException $e) {
                $group->last_error = 'Archiveren mislukt: ' . $e->reason();
                $group->save();
                $failed++;
            }
        }

        if ($failed === 0) {
            $cohort->offboarded_at = now();
            $cohort->save();
        }

        Audit::record('cohort.offboarding_finished', $cohort, ['done' => $done, 'failed' => $failed]);

        return ['done' => $done, 'failed' => $failed];
    }

    private function performStudentAction(Student $student, string $type): void
    {
        match ($type) {
            'remove_team' => $this->membership->removeFromTeam($student, $student->group, 'offboarding'),
            'remove_org' => $this->membership->removeFromOrganization($student),
        };
    }

    private function archiveRepository(Group $group): void
    {
        $this->github->org()->patch(
            '/repos/' . rawurlencode($group->cohort->organization) . '/' . rawurlencode($group->repository_name),
            ['archived' => true],
            allow: [404],
        );

        $group->archived_at = now();
        $group->status = GroupStatus::Archived;
        $group->save();

        Audit::record('repository.archived', $group, ['repository' => $group->repository_name]);
    }
}
