<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only record of changes we make on GitHub and of admin actions.
 *
 * @property string $actor_type
 * @property ?string $actor_id
 * @property ?string $actor_name
 * @property string $action
 * @property ?array $details
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'cohort_id',
        'actor_type',
        'actor_id',
        'actor_name',
        'action',
        'subject_type',
        'subject_id',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
