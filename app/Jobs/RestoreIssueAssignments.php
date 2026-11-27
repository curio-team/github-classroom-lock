<?php

namespace App\Jobs;

use App\GitHub\GitHubException;
use App\GitHub\RateLimitedException;
use App\Models\Group;
use App\Services\Audit;
use App\Services\Exam\IssueAssignments;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After unlocking, assigns the group's students again to the issues they were
 * assigned to before the lock (see IssueAssignments).
 *
 * The snapshot is only cleared after a later check, with a delay, finds
 * nothing missing: GitHub unassigns in the background, so after a quick lock
 * and unlock it may still be removing assignments when the first run is done.
 * And GitHub silently ignores assignees it can't add.
 */
class RestoreIssueAssignments implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $tries = 10;

    public function __construct(public Group $group, public int $attempt = 1)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->group->id;
    }

    public function handle(IssueAssignments $assignments): void
    {
        $group = $this->group->fresh(['cohort']);

        // Locked again (or being locked): the next unlock restores. Closed
        // exams have nobody left to assign.
        if (! $group || $group->isLocked() || $group->lock_pending || $group->archived_at || $group->cohort->offboarding_started_at
            || ! $group->assignment_snapshot || ! $group->hasRepositoryOnGitHub() || ! $group->hasTeamOnGitHub()) {
            return;
        }

        $error = null;

        try {
            $missing = Audit::asSystem(fn () => $assignments->restore($group));
        } catch (RateLimitedException $e) {
            $this->release($e->retryAfter + 5);

            return;
        } catch (GitHubException $e) {
            $missing = null;
            $error = IssueAssignments::reason($e);
        }

        if ($missing === 0 && $this->attempt > 1) {
            $group->assignment_snapshot = null;
            $group->assignment_error = null;
            $group->save();

            return;
        }

        if ($this->attempt >= max(2, config('github.assignments.attempts'))) {
            // Keep the snapshot: the next lock adds to it and the next unlock tries again.
            $group->assignment_error = 'Toewijzingen van issues terugzetten mislukt: ' . ($error
                ?? $missing . ' ' . ($missing === 1 ? 'toewijzing kon' : 'toewijzingen konden') . ' niet worden teruggezet.');
            $group->save();

            Audit::asSystem(fn () => Audit::record('group.assignments_restore_failed', $group, [
                'error' => $error,
                'missing' => $missing,
            ]));

            return;
        }

        self::dispatch($group, $this->attempt + 1)->delay(config('github.assignments.verify_delay'));
    }
}
