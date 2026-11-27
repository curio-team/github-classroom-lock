<?php

namespace App\Services\Exam;

use App\GitHub\GitHub;
use App\GitHub\GitHubException;
use App\GitHub\RateLimitedException;
use App\Jobs\ApplyGroupLock;
use App\Jobs\RestoreIssueAssignments;
use App\Models\Cohort;
use App\Models\Group;
use App\Services\Audit;
use App\Services\Provisioning\GroupProvisioner;
use Illuminate\Support\Facades\Cache;

/**
 * Locking an exam group revokes its team's access to the group repository
 * and Project; unlocking restores it. Students stay members of the team.
 *
 * Losing access to the repository makes GitHub unassign them from its issues
 * and pull requests, so the assignments are stored before locking and
 * restored after unlocking (see IssueAssignments).
 */
class ExamLock
{
    public function __construct(
        private readonly GitHub $github,
        private readonly GroupProvisioner $provisioner,
        private readonly IssueAssignments $assignments,
    ) {
    }

    /**
     * Records the desired state and queues applying it on GitHub.
     */
    public function setLocked(Group $group, bool $locked): void
    {
        if ($group->isLocked() === $locked && ! $group->lock_error) {
            return;
        }

        $group->locked_at = $locked ? now() : null;
        $group->lock_pending = true;
        $group->lock_error = null;
        $group->save();

        Audit::record($locked ? 'group.lock_requested' : 'group.unlock_requested', $group);

        ApplyGroupLock::dispatch($group);
    }

    /**
     * @return int the number of groups whose state changed
     */
    public function setCohortLocked(Cohort $cohort, bool $locked): int
    {
        $changed = 0;

        foreach ($cohort->groups()->whereNull('archived_at')->get() as $group) {
            if ($group->isLocked() !== $locked || $group->lock_error) {
                $this->setLocked($group, $locked);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Applies the group's lock state on GitHub. Idempotent.
     *
     * @throws RateLimitedException
     */
    public function apply(Group $group): bool
    {
        $group->loadMissing('cohort');
        $locked = $group->isLocked();

        // Not on GitHub yet: provisioning will create it in the right state.
        if (! $group->team_slug || ! $group->repository_name || ! $group->project_node_id) {
            $group->lock_pending = false;
            $group->save();

            return true;
        }

        if ($locked) {
            $this->saveAssignments($group);
        }

        try {
            $this->provisioner->ensureRepositoryAccess($group);
            $this->provisioner->ensureProjectAccess($group);
        } catch (RateLimitedException $e) {
            throw $e;
        } catch (GitHubException $e) {
            $group->lock_pending = false;
            $group->lock_error = ($locked ? 'Vergrendelen' : 'Ontgrendelen') . ' mislukt: ' . $e->reason();
            $group->save();

            Audit::record($locked ? 'group.lock_failed' : 'group.unlock_failed', $group, ['error' => $e->reason()]);

            return false;
        }

        $group->lock_pending = false;
        $group->lock_error = null;
        $group->save();

        Audit::record($locked ? 'group.locked' : 'group.unlocked', $group, [
            'team' => $group->team_slug,
            'repository' => $group->repository_name,
        ]);

        if (! $locked && $group->assignment_snapshot) {
            // A new attempt: an earlier restore error no longer applies.
            $group->assignment_error = null;
            $group->save();

            RestoreIssueAssignments::dispatch($group);
        }

        return true;
    }

    /**
     * Stores the issue assignments before the access (and with it, the
     * assignments) is removed. A failure doesn't stop the lock: closing the
     * exam matters more than keeping the assignments.
     *
     * @throws RateLimitedException
     */
    private function saveAssignments(Group $group): void
    {
        try {
            $this->assignments->snapshot($group);
        } catch (RateLimitedException $e) {
            throw $e;
        } catch (GitHubException $e) {
            $group->assignment_error = 'Toewijzingen van issues bewaren mislukt: ' . IssueAssignments::reason($e);
            $group->save();

            Audit::record('group.assignments_save_failed', $group, ['error' => $e->reason()]);
        }
    }

    /*
     * Organization-wide Projects switch (fallback, see GroupProvisioner::ensureProjectAccess)
     */

    public function organizationProjectsEnabled(): ?bool
    {
        return Cache::remember('github-org-projects:' . $this->github->organization(), 60, function () {
            $value = $this->github->org()->get('/orgs/' . rawurlencode($this->github->organization()))->json('has_organization_projects');

            return $value === null ? null : (bool) $value;
        });
    }

    public function setOrganizationProjects(bool $enabled): void
    {
        $this->github->org()->patch('/orgs/' . rawurlencode($this->github->organization()), [
            'has_organization_projects' => $enabled,
        ]);

        Cache::forget('github-org-projects:' . $this->github->organization());
        Cache::forget('org-checks:' . $this->github->organization());

        Audit::record($enabled ? 'org.projects_enabled' : 'org.projects_disabled');
    }
}
