<?php

namespace App\Models;

use App\Enums\GroupStatus;
use App\Enums\ProvisioningStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pre-formed group of students. Gets one team, one private repository and
 * one Project on GitHub.
 *
 * @property int $id
 * @property int $cohort_id
 * @property string $name
 * @property string $slug
 * @property ?int $team_id
 * @property ?string $team_slug
 * @property ?string $team_node_id
 * @property ?int $repository_id
 * @property ?string $repository_name
 * @property ?string $repository_node_id
 * @property ?string $repository_url
 * @property ?string $project_node_id
 * @property ?int $project_number
 * @property ?string $project_url
 * @property GroupStatus $status
 * @property array $completed_steps
 * @property ?string $failed_step
 * @property ?string $last_error
 * @property ?Carbon $provisioned_at
 * @property ?Carbon $archived_at
 * @property ?Carbon $locked_at
 * @property bool $lock_pending
 * @property ?string $lock_error
 * @property ?Carbon $team_deleted_at
 * @property ?Carbon $repository_deleted_at
 * @property ?Carbon $project_deleted_at
 * @property-read Cohort $cohort
 */
class Group extends Model
{
    protected $table = 'cohort_groups';

    protected $fillable = [
        'cohort_id',
        'name',
        'slug',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => GroupStatus::class,
            'completed_steps' => 'array',
            'provisioned_at' => 'datetime',
            'archived_at' => 'datetime',
            'locked_at' => 'datetime',
            'lock_pending' => 'boolean',
            'team_deleted_at' => 'datetime',
            'repository_deleted_at' => 'datetime',
            'project_deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Provisioning creates the group on GitHub in this state, so a
        // locked group never gives its team access until it is unlocked.
        static::creating(function (Group $group) {
            if ($group->locked_at === null && $group->cohort->newGroupsStartLocked()) {
                $group->locked_at = now();
            }
        });
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class)->orderBy('github_login');
    }

    /**
     * The name used for the team, repository and project on GitHub. Only
     * lowercase letters, digits and hyphens, so the team slug GitHub derives
     * from it is identical (which makes lookups for idempotency reliable).
     */
    public function githubName(): string
    {
        return Str::limit(Str::slug($this->cohort->slug . '-' . $this->slug), 100, '');
    }

    public function hasCompleted(ProvisioningStep $step): bool
    {
        return in_array($step->value, $this->completed_steps ?? [], true);
    }

    public function markCompleted(ProvisioningStep $step): void
    {
        $steps = $this->completed_steps ?? [];

        if (! in_array($step->value, $steps, true)) {
            $steps[] = $step->value;
        }

        $this->completed_steps = $steps;
        $this->save();
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function hasRepositoryOnGitHub(): bool
    {
        return $this->repository_name !== null && $this->repository_deleted_at === null;
    }

    public function hasTeamOnGitHub(): bool
    {
        return $this->team_slug !== null && $this->team_deleted_at === null;
    }

    public function hasProjectOnGitHub(): bool
    {
        return $this->project_node_id !== null && $this->project_deleted_at === null;
    }

    public function isReadyForStudents(): bool
    {
        return $this->status === GroupStatus::Provisioned && $this->team_slug !== null && $this->team_id !== null;
    }
}
