<?php

namespace App\Jobs;

use App\GitHub\RateLimitedException;
use App\Models\Cohort;
use App\Services\Audit;
use App\Services\Offboarding\GitHubCleanup;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CleanUpCohort implements ShouldQueue, ShouldBeUnique
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

    public function handle(GitHubCleanup $cleanup): void
    {
        try {
            Audit::asSystem(fn () => $cleanup->execute($this->cohort, $this->options));
        } catch (RateLimitedException $e) {
            // Deleted resources are skipped on the next attempt.
            $this->release($e->retryAfter + 5);

            return;
        }

        $this->cohort->forceFill(['cleanup_pending' => false])->save();
    }

    public function failed(?Throwable $e): void
    {
        $this->cohort->forceFill(['cleanup_pending' => false])->save();
    }
}
