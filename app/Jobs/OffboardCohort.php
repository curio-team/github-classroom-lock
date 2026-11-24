<?php

namespace App\Jobs;

use App\GitHub\RateLimitedException;
use App\Models\Cohort;
use App\Services\Audit;
use App\Services\Offboarding\Offboarder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class OffboardCohort implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 1800;

    public function __construct(public Cohort $cohort, public array $options)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->cohort->id;
    }

    public function handle(Offboarder $offboarder): void
    {
        try {
            Audit::asSystem(fn () => $offboarder->execute($this->cohort, $this->options));
        } catch (RateLimitedException $e) {
            // Completed actions are skipped or are no-ops on the next attempt.
            $this->release($e->retryAfter + 5);
        }
    }
}
