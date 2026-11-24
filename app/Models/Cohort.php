<?php

namespace App\Models;

use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A class of students that is onboarded together, with one invite link.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $organization
 * @property ?string $template_repository
 * @property ?int $template_project_number
 * @property ?string $invite_token
 * @property ?string $invite_token_hash
 * @property ?Carbon $invite_expires_at
 * @property ?Carbon $invite_revoked_at
 * @property ?Carbon $offboarding_started_at
 * @property ?Carbon $offboarded_at
 */
class Cohort extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'organization',
        'template_repository',
        'template_project_number',
        'created_by',
    ];

    protected $hidden = [
        'invite_token',
        'invite_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'invite_token' => 'encrypted',
            'invite_expires_at' => 'datetime',
            'invite_revoked_at' => 'datetime',
            'offboarding_started_at' => 'datetime',
            'offboarded_at' => 'datetime',
            'template_project_number' => 'integer',
        ];
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class)->orderBy('name');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class)->orderBy('github_login');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /*
     * Invite link
     */

    public static function hashInviteToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Creates a new high-entropy invite token, replacing (and thereby
     * invalidating) any previous link. Returns the plain token.
     */
    public function generateInviteLink(?Carbon $expiresAt = null): string
    {
        $token = Str::random(48);

        $this->invite_token = $token;
        $this->invite_token_hash = self::hashInviteToken($token);
        $this->invite_expires_at = $expiresAt ?? now()->addDays(config('github.limits.invite_link_days'));
        $this->invite_revoked_at = null;
        $this->save();

        return $token;
    }

    public function revokeInviteLink(): void
    {
        $this->invite_revoked_at = now();
        $this->save();
    }

    public static function findByInviteToken(?string $token): ?self
    {
        if (! is_string($token) || strlen($token) < 32 || strlen($token) > 128) {
            return null;
        }

        return self::where('invite_token_hash', self::hashInviteToken($token))->first();
    }

    /**
     * One of: none, valid, expired, revoked, offboarded.
     */
    public function inviteLinkStatus(): string
    {
        return match (true) {
            $this->invite_token_hash === null => 'none',
            $this->offboarding_started_at !== null => 'offboarded',
            $this->invite_revoked_at !== null => 'revoked',
            $this->invite_expires_at !== null && $this->invite_expires_at->isPast() => 'expired',
            default => 'valid',
        };
    }

    public function inviteLinkIsUsable(): bool
    {
        return $this->inviteLinkStatus() === 'valid';
    }

    public function inviteUrl(): ?string
    {
        return $this->invite_token ? route('join.show', ['token' => $this->invite_token]) : null;
    }

    /**
     * Counts of students per status, for the overview.
     *
     * @return array<string, int>
     */
    public function studentStatusCounts(): array
    {
        $counts = $this->students()
            ->reorder()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $result = [];

        foreach (StudentStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }
}
