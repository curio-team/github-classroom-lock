<?php

namespace App\Jobs;

use App\GitHub\RateLimitedException;
use App\Models\Group;
use App\Services\Audit;
use App\Services\Exam\ExamLock;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a group's current lock state. It reads the state when it runs, so
 * quickly toggling lock/unlock always ends in the last chosen state.
 */
class ApplyGroupLock implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $tries = 10;

    public function __construct(public Group $group)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->group->id;
    }

    public function handle(ExamLock $lock): void
    {
        try {
            Audit::asSystem(fn () => $lock->apply($this->group->fresh()));
        } catch (RateLimitedException $e) {
            $this->release($e->retryAfter + 5);
        }
    }
}
