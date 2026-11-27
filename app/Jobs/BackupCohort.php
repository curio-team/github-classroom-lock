<?php

namespace App\Jobs;

use App\Models\Cohort;
use App\Services\Audit;
use App\Services\Backup\RepositoryBackup;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class BackupCohort implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public Cohort $cohort)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->cohort->id;
    }

    public function handle(RepositoryBackup $backup): void
    {
        Audit::asSystem(fn () => $backup->create($this->cohort));
    }

    public function failed(?Throwable $e): void
    {
        $this->cohort->forceFill([
            'backup_status' => 'failed',
            'backup_error' => $e?->getMessage() ?? 'Onbekende fout',
        ])->save();

        Audit::asSystem(fn () => Audit::record('backup.failed', $this->cohort, ['error' => $e?->getMessage()]));
    }
}
