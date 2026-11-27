<?php

namespace App\Services\Join;

use App\Enums\StudentStatus;
use App\GitHub\GitHub;
use App\GitHub\GitHubException;
use App\GitHub\RateLimitedException;
use App\Models\Group;
use App\Models\Student;
use App\Services\Audit;
use App\Services\GitHubMembership;
use App\Services\GitHubUsers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Adds a roster student to the organization and their group's team:
 *
 * 1. Create an organization invitation (server credentials), including the group's team.
 * 2. Accept it with the student's own token (PATCH /user/memberships/orgs/{org}).
 * 3. Confirm org and team membership.
 *
 * Safe to repeat (page refresh, admin retry, partial failure): every step
 * first checks the current state on GitHub. A per-student lock prevents two
 * concurrent attempts from creating duplicate invitations.
 *
 * The student's user token is only needed to accept the invitation and is
 * never stored. Without it (status refresh, admin retry) everything else
 * still works with the server credentials.
 */
class JoinService
{
    public function __construct(
        private readonly GitHub $github,
        private readonly GitHubUsers $users,
        private readonly GitHubMembership $membership,
    ) {
    }

    /**
     * @param  array|null  $githubUser  the authenticated user (GET /user) when called from the OAuth callback
     */
    public function process(Student $student, #[\SensitiveParameter] ?string $userToken = null, ?array $githubUser = null): JoinResult
    {
        $lock = Cache::lock('join-student:' . $student->id, 120);

        if (! $lock->get()) {
            return JoinResult::processing('Er loopt al een poging om je toe te voegen. Ververs de pagina over een paar seconden.');
        }

        try {
            return $this->run($student->fresh(['cohort', 'group', 'previousGroup']), $userToken, $githubUser);
        } catch (RateLimitedException $e) {
            Audit::record('student.join_rate_limited', $student, ['retry_after' => $e->retryAfter]);

            return JoinResult::processing('GitHub is even erg druk. Ververs de pagina over een minuut om het opnieuw te proberen.');
        } catch (GitHubException $e) {
            $student->markFailed($e->reason());
            Audit::record('student.join_failed', $student, ['error' => $e->reason()]);

            return JoinResult::failed('Er ging iets mis bij GitHub. Je examinator kan zien wat er misging en het opnieuw proberen.');
        } finally {
            $lock->release();
        }
    }

    private function run(Student $student, ?string $userToken, ?array $githubUser): JoinResult
    {
        $student->last_attempt_at = now();
        $student->save();

        $cohort = $student->cohort;
        $group = $student->group;

        if ($cohort->offboarding_started_at !== null) {
            return JoinResult::failed('Dit examen is afgesloten.');
        }

        if ($group === null || ! $group->isReadyForStudents()) {
            $student->markFailed('De groep is nog niet aangemaakt op GitHub (provisioning).');

            return JoinResult::failed('Je groep is nog niet klaar op GitHub. Vraag je examinator om de groepen klaar te zetten en probeer het daarna opnieuw.');
        }

        $this->refreshLogin($student, $githubUser);

        $result = $this->join($student, $group, $userToken, $githubUser);

        if ($result->isJoined()) {
            $this->removeFromPreviousTeam($student, $group);

            $wasJoined = $student->status === StudentStatus::Joined;
            $student->status = StudentStatus::Joined;
            $student->joined_at ??= now();
            $student->failure_reason = null;
            $student->save();

            if (! $wasJoined) {
                Audit::record('student.joined', $student, ['team' => $group->team_slug]);
            }
        }

        return $result;
    }

    /**
     * Logins can change; endpoints that take a username need the current one.
     */
    private function refreshLogin(Student $student, ?array $githubUser): void
    {
        $login = $githubUser['login'] ?? $this->users->findById($student->github_user_id)['login'] ?? null;

        if ($login === null) {
            throw new GitHubException("GitHub account {$student->github_user_id} no longer exists.");
        }

        if ($login !== $student->github_login) {
            Audit::record('student.login_changed', $student, ['from' => $student->github_login, 'to' => $login]);

            $student->github_login = $login;
            $student->save();
        }
    }

    private function join(Student $student, Group $group, ?string $userToken, ?array $githubUser): JoinResult
    {
        $invitationUrl = $this->github->invitationUrl();

        if ($githubUser !== null && ($githubUser['two_factor_authentication'] ?? null) === false && $this->organizationRequires2fa()) {
            $student->markFailed('Student heeft geen tweestapsverificatie (2FA) aan, maar de organisatie vereist dat.');

            return JoinResult::actionRequired(
                'De organisatie vereist tweestapsverificatie (2FA). Zet 2FA aan op GitHub en open daarna de uitnodigingslink opnieuw.',
                'https://github.com/settings/security',
                '2FA instellen op GitHub',
            );
        }

        $membership = $this->orgMembership($student);

        if ($membership === null) {
            // No pending invitation (never invited, or it expired after 7 days).
            $this->createInvitation($student, $group);
            $membership = ['state' => 'pending'];
        }

        if ($membership['state'] === 'pending') {
            if ($userToken === null) {
                return JoinResult::actionRequired(
                    'Je hebt een uitnodiging voor de organisatie. Accepteer die op GitHub en ververs daarna deze pagina.',
                    $invitationUrl,
                    'Uitnodiging accepteren',
                );
            }

            $org = rawurlencode($student->cohort->organization);
            $response = $this->github->user($userToken)->patch("/user/memberships/orgs/{$org}", ['state' => 'active'], allow: [403, 404, 422]);

            if ($response->status() === 200) {
                Audit::record('org.invitation_accepted', $student);
            } elseif ($response->status() === 202) {
                Audit::record('org.invitation_accepted', $student, ['async' => true]);

                if ($this->waitForActiveMembership($student) === null) {
                    return JoinResult::processing('GitHub verwerkt je uitnodiging. Ververs de pagina over een paar seconden.');
                }
            } elseif (($this->orgMembership($student)['state'] ?? null) === 'active') {
                // The accept call failed, but the student is a member anyway
                // (accepted on github.com in the meantime, or GitHub answered
                // with an error after processing it). Nothing left to accept.
                Audit::record('org.invitation_accepted', $student, ['status' => $response->status(), 'verified_by' => 'membership']);
            } else {
                $reason = sprintf('Automatisch accepteren mislukt (%d: %s).', $response->status(), $response->json('message') ?? 'onbekend');
                $student->failure_reason = $reason;
                $student->save();

                Audit::record('org.invitation_accept_failed', $student, ['status' => $response->status(), 'message' => $response->json('message')]);

                return JoinResult::actionRequired(
                    'We konden je uitnodiging niet automatisch accepteren. Accepteer hem op GitHub en ververs daarna deze pagina.',
                    $invitationUrl,
                    'Uitnodiging accepteren',
                );
            }
        }

        return $this->teamResult($student, $this->ensureTeamMembership($student, $group));
    }

    private function createInvitation(Student $student, Group $group): void
    {
        $limit = config('github.limits.invitations_per_day');

        if (Audit::invitationsInLastDay() >= $limit) {
            throw new GitHubException("Invitation limit reached ({$limit} per 24 hours). Retry later.");
        }

        $org = rawurlencode($student->cohort->organization);
        $response = $this->github->org()->post("/orgs/{$org}/invitations", [
            'invitee_id' => $student->github_user_id,
            'role' => 'direct_member',
            'team_ids' => [$group->team_id],
        ], allow: [422]);

        if ($response->status() === 422) {
            // Possibly invited concurrently. Otherwise surface GitHub's reason
            // (e.g. no seats available).
            if ($this->orgMembership($student) !== null) {
                return;
            }

            throw GitHubException::fromResponse('POST', "/orgs/{$org}/invitations", $response);
        }

        $student->invitation_id = $response->json('id');
        $student->invited_at = now();

        if ($student->status !== StudentStatus::Joined) {
            $student->status = StudentStatus::Invited;
        }

        $student->failure_reason = null;
        $student->save();

        Audit::record('org.invitation_created', $student, ['invitation_id' => $student->invitation_id, 'team_id' => $group->team_id]);
    }

    /**
     * @return array{state: string, role?: string}|null null when not a member and not invited
     */
    public function orgMembership(Student $student): ?array
    {
        $response = $this->github->org()->get(sprintf(
            '/orgs/%s/memberships/%s',
            rawurlencode($student->cohort->organization),
            rawurlencode($student->github_login),
        ), allow: [404]);

        return $response->status() === 404 ? null : $response->json();
    }

    private function waitForActiveMembership(Student $student): ?array
    {
        $attempts = max(1, config('github.membership_poll.attempts'));

        for ($i = 1; $i <= $attempts; $i++) {
            $membership = $this->orgMembership($student);

            if (($membership['state'] ?? null) === 'active') {
                return $membership;
            }

            if ($i < $attempts) {
                Sleep::for(config('github.membership_poll.sleep_ms'))->milliseconds();
            }
        }

        return null;
    }

    /**
     * @return string the team membership state: active or pending
     */
    private function ensureTeamMembership(Student $student, Group $group): string
    {
        $path = sprintf(
            '/orgs/%s/teams/%s/memberships/%s',
            rawurlencode($student->cohort->organization),
            rawurlencode($group->team_slug),
            rawurlencode($student->github_login),
        );

        $response = $this->github->org()->get($path, allow: [404]);

        if ($response->successful()) {
            $state = $response->json('state');
        } else {
            $state = $this->github->org()->put($path, ['role' => 'member'])->json('state');

            Audit::record('team.member_added', $student, ['team' => $group->team_slug, 'state' => $state]);
        }

        // The student is an active organization member at this point, so a
        // pending team membership only means GitHub hasn't finished applying
        // the accepted invitation to the team yet. Give it a moment.
        $attempts = max(1, config('github.membership_poll.attempts'));

        for ($i = 1; $state !== 'active' && $i <= $attempts; $i++) {
            Sleep::for(config('github.membership_poll.sleep_ms'))->milliseconds();

            $state = $this->github->org()->get($path, allow: [404])->json('state') ?? $state;
        }

        return $state;
    }

    private function teamResult(Student $student, string $teamState): JoinResult
    {
        if ($teamState === 'active') {
            return JoinResult::joined();
        }

        // Still catching up. There is no invitation left for the student to
        // accept, so don't send them to GitHub: the status page re-checks.
        $student->failure_reason = 'Teamlidmaatschap staat nog op "pending" bij GitHub; wordt opnieuw gecontroleerd.';
        $student->save();

        Audit::record('team.membership_pending', $student, ['team' => $student->group?->team_slug]);

        return JoinResult::processing('GitHub voegt je toe aan je groep. Deze pagina ververst vanzelf.');
    }

    private function removeFromPreviousTeam(Student $student, Group $current): void
    {
        $previous = $student->previousGroup;

        if ($previous === null) {
            return;
        }

        if ($previous->hasTeamOnGitHub() && $previous->id !== $current->id) {
            $this->membership->removeFromTeam($student, $previous, 'moved');
        }

        $student->previous_group_id = null;
        $student->save();
    }

    private function organizationRequires2fa(): bool
    {
        return (bool) Cache::remember('github-org-2fa:' . $this->github->organization(), 600, function () {
            return $this->github->org()->get('/orgs/' . rawurlencode($this->github->organization()))
                ->json('two_factor_requirement_enabled');
        });
    }
}
