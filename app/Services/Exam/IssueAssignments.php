<?php

namespace App\Services\Exam;

use App\GitHub\GitHub;
use App\GitHub\GitHubException;
use App\GitHub\RateLimitedException;
use App\Models\Group;
use App\Services\Audit;

/**
 * GitHub unassigns people from a repository's issues and pull requests when
 * they lose access to it. Locking revokes the team's access, so before that
 * we store which team member was assigned to what, and after unlocking we
 * assign them again. (Draft issues in the Project aren't affected: they don't
 * belong to the repository.)
 */
class IssueAssignments
{
    public function __construct(private readonly GitHub $github)
    {
    }

    /**
     * Stores the current assignments of the group's team members, merged with
     * any earlier snapshot that hasn't been restored yet. Merging (instead of
     * replacing) matters for a retried lock: by then the access is gone, the
     * assignments too, and a fresh snapshot would find nothing.
     *
     * @throws GitHubException
     * @throws RateLimitedException
     */
    public function snapshot(Group $group): void
    {
        $members = $this->teamMembers($group);
        $snapshot = $group->assignment_snapshot ?? [];

        foreach ($this->assignees($group) as $number => $logins) {
            $ofMembers = array_filter($logins, fn (string $login) => isset($members[strtolower($login)]));

            if ($ofMembers) {
                $snapshot[$number] = array_values(array_unique(array_merge($snapshot[$number] ?? [], $ofMembers)));
            }
        }

        ksort($snapshot);

        $group->assignment_snapshot = $snapshot ?: null;
        $group->assignment_error = null;
        $group->save();

        if ($snapshot) {
            Audit::record('group.assignments_saved', $group, ['assignments' => self::count($snapshot)]);
        }
    }

    /**
     * Assigns team members again to the issues they were assigned to before
     * the lock, where they aren't now. People who left the team in the
     * meantime are skipped, as are issues that no longer exist.
     *
     * @return int the number of assignments that were missing and have been requested again
     *
     * @throws GitHubException
     * @throws RateLimitedException
     */
    public function restore(Group $group): int
    {
        $snapshot = $group->assignment_snapshot ?? [];

        if (! $snapshot) {
            return 0;
        }

        $members = $this->teamMembers($group);
        $current = $this->assignees($group);
        $requested = 0;

        foreach ($snapshot as $number => $logins) {
            if (! isset($current[$number])) {
                continue;
            }

            $assigned = array_map('strtolower', $current[$number]);
            $missing = array_values(array_filter(
                $logins,
                fn (string $login) => isset($members[strtolower($login)]) && ! in_array(strtolower($login), $assigned, true),
            ));

            if ($missing) {
                // Adds to the current assignees (never removes). GitHub silently
                // ignores people it can't assign, so the caller verifies later.
                $this->github->org()->post($this->repoPath($group) . "/issues/{$number}/assignees", ['assignees' => $missing]);
                $requested += count($missing);
            }
        }

        if ($requested > 0) {
            Audit::record('group.assignments_restored', $group, ['assignments' => $requested]);
        }

        return $requested;
    }

    /**
     * The error for teachers, with a hint for the likely cause of a 403.
     */
    public static function reason(GitHubException $e): string
    {
        return $e->reason() . ($e->status === 403 ? ' (Heeft de GitHub App de rechten Issues en Pull requests: Read & write?)' : '');
    }

    public static function count(?array $snapshot): int
    {
        return array_sum(array_map('count', $snapshot ?? []));
    }

    /**
     * All issues and pull requests of the group repository (open and closed).
     *
     * @return array<int, array<int, string>> issue number => assignee logins
     */
    private function assignees(Group $group): array
    {
        $issues = $this->github->org()->paginate($this->repoPath($group) . '/issues', ['state' => 'all']);
        $assignees = [];

        foreach ($issues as $issue) {
            $assignees[(int) $issue['number']] = array_map(fn (array $user) => $user['login'], $issue['assignees'] ?? []);
        }

        return $assignees;
    }

    /**
     * @return array<string, true> lowercase logins of the team's (active) members
     */
    private function teamMembers(Group $group): array
    {
        $members = $this->github->org()->paginate('/orgs/' . $this->org($group) . '/teams/' . rawurlencode($group->team_slug) . '/members');

        return array_fill_keys(array_map(fn (array $user) => strtolower($user['login']), $members), true);
    }

    private function repoPath(Group $group): string
    {
        return '/repos/' . $this->org($group) . '/' . rawurlencode($group->repository_name);
    }

    private function org(Group $group): string
    {
        return rawurlencode($group->loadMissing('cohort')->cohort->organization);
    }
}
