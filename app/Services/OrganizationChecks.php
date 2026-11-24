<?php

namespace App\Services;

use App\Enums\StudentStatus;
use App\GitHub\GitHub;
use App\GitHub\GitHubException;
use App\Models\Student;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Pre-flight checks shown to admins: base permission, 2FA requirement,
 * available licenses and the invitation rate limit.
 */
class OrganizationChecks
{
    public function __construct(private readonly GitHub $github)
    {
    }

    /**
     * @return array<int, array{key: string, label: string, level: string, message: string}>
     *                                                                                        level: ok, warning, error, info
     */
    public function run(bool $fresh = false): array
    {
        $key = 'org-checks:' . $this->github->organization();

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 300, fn () => $this->compute());
    }

    private function compute(): array
    {
        $checks = [];
        $org = null;

        try {
            $org = $this->github->org()->get('/orgs/' . rawurlencode($this->github->organization()))->json();
        } catch (Throwable $e) {
            $checks[] = $this->check('access', 'Toegang tot organisatie', 'error', $this->message($e));
        }

        if ($org) {
            $permission = $org['default_repository_permission'] ?? null;

            $checks[] = $permission === null
                ? $this->check('base_permission', 'Basisrechten repositories', 'warning', 'Onbekend: de server-token is waarschijnlijk geen organisatie-eigenaar.')
                : $this->check(
                    'base_permission',
                    'Basisrechten repositories',
                    $permission === 'none' ? 'ok' : 'error',
                    $permission === 'none'
                        ? 'No permission: studenten zien alleen de repository van hun eigen team.'
                        : "Staat op '{$permission}': elk lid kan alle repositories zien. Zet dit op 'No permission'.",
                );

            $checks[] = $this->check(
                'two_factor',
                'Tweestapsverificatie',
                'info',
                ($org['two_factor_requirement_enabled'] ?? false)
                    ? 'Vereist: studenten moeten 2FA aanzetten voordat ze een uitnodiging kunnen accepteren.'
                    : 'Niet vereist door de organisatie.',
            );

            $checks[] = $this->seatsCheck($org['plan'] ?? null);
        }


        $used = Audit::invitationsInLastDay();
        $limit = config('github.limits.invitations_per_day');
        $checks[] = $this->check(
            'invitation_limit',
            'Uitnodigingen (24 uur)',
            $used >= $limit ? 'error' : ($used >= $limit * 0.8 ? 'warning' : 'ok'),
            "{$used} van {$limit} gebruikt.",
        );

        return $checks;
    }

    private function seatsCheck(?array $plan): array
    {
        if (! $plan || ! isset($plan['seats'], $plan['filled_seats'])) {
            return $this->check('seats', 'Licenties (organisatie)', 'info', 'Onbekend (alleen zichtbaar voor eigenaren).');
        }

        $available = $plan['seats'] - $plan['filled_seats'];
        $needed = $this->studentsStillToJoin();

        return $this->check(
            'seats',
            'Licenties (organisatie)',
            $available >= $needed ? 'ok' : 'error',
            "{$plan['filled_seats']} van {$plan['seats']} in gebruik, {$available} vrij. Nog {$needed} studenten moeten toetreden.",
        );
    }

    private function studentsStillToJoin(): int
    {
        return Student::whereIn('status', [StudentStatus::Imported->value, StudentStatus::Invited->value, StudentStatus::Failed->value])
            ->whereHas('cohort', fn ($query) => $query->whereNull('offboarding_started_at'))
            ->count();
    }

    /**
     * Sets the organization's base repository permission to "No permission".
     */
    public function setBasePermissionToNone(): void
    {
        $this->github->org()->patch('/orgs/' . rawurlencode($this->github->organization()), [
            'default_repository_permission' => 'none',
        ]);

        Audit::record('org.base_permission_set', null, ['permission' => 'none']);
        Cache::forget('org-checks:' . $this->github->organization());
    }

    private function check(string $key, string $label, string $level, string $message): array
    {
        return compact('key', 'label', 'level', 'message');
    }

    private function message(Throwable $e): string
    {
        return $e instanceof GitHubException ? $e->reason() : $e->getMessage();
    }
}
