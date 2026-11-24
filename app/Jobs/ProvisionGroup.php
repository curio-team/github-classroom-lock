<?php

namespace App\Jobs;

use App\Enums\GroupStatus;
use App\GitHub\RateLimitedException;
use App\Models\Group;
use App\Services\Audit;
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

    public function handle(GroupProvisioner $provisioner): void
    {
        try {
            Audit::asSystem(fn () => $provisioner->provision($this->group));
        } catch (RateLimitedException $e) {
            // Secondary rate limit: try again later, resuming at the failed step.
            $this->release($e->retryAfter + 5);
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
