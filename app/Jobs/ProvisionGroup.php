<?php

namespace App\Jobs;

use App\Enums\GroupStatus;
use App\Enums\StudentStatus;
use App\GitHub\RateLimitedException;
use App\Models\Group;
use App\Services\Audit;
use App\Services\Join\JoinService;
use App\Services\Provisioning\GroupProvisioner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionGroup implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 10;

    public int $uniqueFor = 600;

    public function __construct(public Group $group)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->group->id;
    }

    public function handle(GroupProvisioner $provisioner, JoinService $joinService): void
    {
        try {
            if (Audit::asSystem(fn () => $provisioner->provision($this->group))) {
                Audit::asSystem(fn () => $this->finishMoves($joinService));
            }
        } catch (RateLimitedException $e) {
            // Secondary rate limit: try again later, resuming at the failed step.
            $this->release($e->retryAfter + 5);
        }
    }

    /**
     * Students who had already joined and were moved into this group before
     * it existed on GitHub: add them to the new team (and take them out of
     * the old one) now that it does.
     */
    private function finishMoves(JoinService $joinService): void
    {
        $students = $this->group->students()
            ->whereNotNull('previous_group_id')
            ->whereIn('status', [StudentStatus::Imported->value, StudentStatus::Failed->value])
            ->get();

        foreach ($students as $student) {
            $joinService->process($student);
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->group->refresh();

        if ($this->group->status !== GroupStatus::Failed) {
            $this->group->status = GroupStatus::Failed;
            $this->group->last_error = $e->getMessage();
            $this->group->save();
        }
    }
}
