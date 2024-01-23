<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $id
 * @property string $name
 * @property bool $locked
 *
 */
class Team extends Model
{
    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('archived', function (Builder $builder) {
            $builder->where('is_archived', false);
        });
    }

    /**
     * Scope to include archived teams.
     */
    public function scopeWithArchived(Builder $query): void
    {
        $query->withoutGlobalScope('archived');
    }

    /**
     * Scope to only get archived teams.
     */
    public function scopeArchived(Builder $query): void
    {
        $query->withoutGlobalScope('archived')->where('is_archived', true);
    }

    /**
     * Relationship to the team members.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function members()
    {
        return $this->hasMany(TeamMember::class);
    }
}
