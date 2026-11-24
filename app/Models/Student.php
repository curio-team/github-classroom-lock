<?php

namespace App\Models;

use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A roster entry: a GitHub account pre-assigned to a group.
 *
 * @property int $id
 * @property int $cohort_id
 * @property ?int $group_id
 * @property ?int $previous_group_id
 * @property int $github_user_id
 * @property string $github_login
 * @property StudentStatus $status
 * @property ?string $failure_reason
 * @property ?int $invitation_id
 * @property ?Carbon $invited_at
 * @property ?Carbon $joined_at
 * @property ?Carbon $last_attempt_at
 * @property ?Carbon $offboarded_at
 * @property-read Cohort $cohort
 * @property-read ?Group $group
 * @property-read ?Group $previousGroup
 */
class Student extends Model
{
    protected $fillable = [
        'cohort_id',
        'group_id',
        'github_user_id',
        'github_login',
    ];

    protected $attributes = [
        'status' => 'imported',
    ];

    protected function casts(): array
    {
        return [
            'github_user_id' => 'integer',
            'status' => StudentStatus::class,
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'offboarded_at' => 'datetime',
        ];
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function previousGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'previous_group_id');
    }

    public function markFailed(string $reason): void
    {
        $this->status = StudentStatus::Failed;
        $this->failure_reason = $reason;
        $this->save();
    }
}
