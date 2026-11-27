<?php

namespace App\Services;

use App\Enums\StudentStatus;
use App\GitHub\GitHub;
use App\Models\Group;
use App\Models\Student;

/**
 * Removes a student's access on GitHub. Every call is idempotent: a 404
 * means it was already done.
 */
class GitHubMembership
{
    public function __construct(private readonly GitHub $github)
    {
    }

    public function removeFromTeam(Student $student, Group $group, string $reason): void
    {
        $this->github->org()->delete(sprintf(
            '/orgs/%s/teams/%s/memberships/%s',
            rawurlencode($student->cohort->organization),
            rawurlencode($group->team_slug),
            rawurlencode($student->github_login),
        ), allow: [404]);

        Audit::record('team.member_removed', $student, ['team' => $group->team_slug, 'reason' => $reason]);
    }

    /**
     * Removes an active member, or cancels a pending invitation.
     *
     * @return bool false when the student was kept because they are an organization owner
     */
    public function removeFromOrganization(Student $student): bool
    {
        $org = rawurlencode($student->cohort->organization);
        $login = rawurlencode($student->github_login);

        // Safety net: never remove an organization owner (staff) that ended up on a roster.
        $membership = $this->github->org()->get("/orgs/{$org}/memberships/{$login}", allow: [404]);

        if ($membership->successful() && $membership->json('role') === 'admin') {
            Audit::record('org.member_kept', $student, ['reason' => 'organization owner']);

            return false;
        }

        $this->github->org()->delete("/orgs/{$org}/memberships/{$login}", allow: [404]);
        Audit::record('org.member_removed', $student);

        return true;
    }

    /**
     * Names of other, not yet offboarded cohorts in the same org that contain this student.
     *
     * @return array<int, string>
     */
    public function otherActiveCohorts(Student $student): array
    {
        return Student::query()
            ->where('github_user_id', $student->github_user_id)
            ->where('cohort_id', '!=', $student->cohort_id)
            ->whereIn('status', [StudentStatus::Joined->value, StudentStatus::Invited->value, StudentStatus::Imported->value])
            ->whereHas('cohort', fn ($query) => $query
                ->where('organization', $student->cohort->organization)
                ->whereNull('offboarding_started_at'))
            ->with('cohort')
            ->get()
            ->pluck('cohort.name')
            ->all();
    }
}
