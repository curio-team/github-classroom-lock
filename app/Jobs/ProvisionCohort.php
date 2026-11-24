<?php

namespace App\Jobs;

use App\Enums\GroupStatus;
use App\Models\Cohort;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queues provisioning of every group of the cohort that isn't archived.
 * Safe to dispatch repeatedly.
 */
class ProvisionCohort implements ShouldQueue
{
    use Queueable;

    public function __construct(public Cohort $cohort)
    {
    }

    public function handle(): void
    {
        foreach ($this->cohort->groups()->where('status', '!=', GroupStatus::Archived->value)->get() as $group) {
            ProvisionGroup::dispatch($group);
        }
    }
}
