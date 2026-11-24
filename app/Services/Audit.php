<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Cohort;
use App\Models\Group;
use App\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Records membership changes and admin actions, both in the database and the
 * application log. Never pass tokens or other secrets in $details.
 */
class Audit
{
    /** @var array{type: string, id: ?string, name: ?string}|null */
    private static ?array $actor = null;

    /**
     * Runs $callback with the given actor attributed to all audit entries.
     */
    public static function as(string $type, ?string $id, ?string $name, callable $callback): mixed
    {
        $previous = self::$actor;
        self::$actor = ['type' => $type, 'id' => $id, 'name' => $name];

        try {
            return $callback();
        } finally {
            self::$actor = $previous;
        }
    }

    public static function asStudent(Student $student, callable $callback): mixed
    {
        return self::as('student', (string) $student->github_user_id, $student->github_login, $callback);
    }

    public static function asSystem(callable $callback): mixed
    {
        return self::as('system', null, 'queue', $callback);
    }

    public static function record(string $action, ?Model $subject = null, array $details = [], ?Cohort $cohort = null): AuditLog
    {
        $actor = self::$actor ?? self::currentActor();
        $cohort ??= self::cohortOf($subject);

        $entry = AuditLog::create([
            'cohort_id' => $cohort?->id,
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'],
            'actor_name' => $actor['name'],
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'details' => $details ?: null,
        ]);

        Log::info("audit: {$action}", [
            'actor' => $actor,
            'cohort' => $cohort?->id,
            'subject' => $subject ? $subject->getMorphClass() . '#' . $subject->getKey() : null,
        ] + $details);

        return $entry;
    }

    /**
     * Number of organization invitations we created in the last 24 hours.
     */
    public static function invitationsInLastDay(): int
    {
        return AuditLog::where('action', 'org.invitation_created')
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    private static function currentActor(): array
    {
        $user = Auth::user();

        if ($user) {
            return ['type' => 'user', 'id' => (string) $user->getAuthIdentifier(), 'name' => $user->name ?? null];
        }

        return ['type' => 'system', 'id' => null, 'name' => app()->runningInConsole() ? 'console' : null];
    }

    private static function cohortOf(?Model $subject): ?Cohort
    {
        return match (true) {
            $subject instanceof Cohort => $subject,
            $subject instanceof Group, $subject instanceof Student => $subject->cohort,
            default => null,
        };
    }
}
