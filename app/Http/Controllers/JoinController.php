<?php

namespace App\Http\Controllers;

use App\GitHub\GitHub;
use App\GitHub\GitHubException;
use App\Models\Cohort;
use App\Models\Student;
use App\Services\Audit;
use App\Services\Join\JoinResult;
use App\Services\Join\JoinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The student side: invite link → GitHub sign-in → roster match → join.
 *
 * The student's OAuth token only lives inside callback(); it's never stored
 * in the session or database.
 */
class JoinController extends Controller
{
    private const SESSION_OAUTH = 'join.oauth';

    public function __construct(private readonly GitHub $github)
    {
    }

    /**
     * Landing page of the invite link.
     */
    public function show(string $token)
    {
        $cohort = Cohort::findByInviteToken($token);

        if (! $cohort || ! $cohort->inviteLinkIsUsable()) {
            return $this->invalidLink($cohort);
        }

        return view('join.show', [
            'cohort' => $cohort,
            'token' => $token,
            'configured' => (bool) config('github.oauth.client_id'),
        ]);
    }

    /**
     * Redirects to GitHub to sign in.
     */
    public function redirect(Request $request, string $token)
    {
        $cohort = Cohort::findByInviteToken($token);

        if (! $cohort || ! $cohort->inviteLinkIsUsable()) {
            return $this->invalidLink($cohort);
        }

        $state = Str::random(40);

        $request->session()->put(self::SESSION_OAUTH, [
            'state' => $state,
            'token' => $token,
        ]);

        $query = http_build_query([
            'client_id' => config('github.oauth.client_id'),
            'redirect_uri' => route('join.callback'),
            'scope' => config('github.oauth.scopes'),
            'state' => $state,
            'allow_signup' => 'true',
        ]);

        return redirect()->away(config('github.oauth.authorize_url') . '?' . $query);
    }

    /**
     * GitHub redirects back here after sign-in.
     */
    public function callback(Request $request, JoinService $joinService)
    {
        $pending = $request->session()->pull(self::SESSION_OAUTH);

        if (! is_array($pending)
            || ! is_string($request->query('state'))
            || ! hash_equals($pending['state'], $request->query('state'))) {
            return response()->view('join.error', [
                'title' => 'Inloggen verlopen',
                'message' => 'Je inlogpoging is verlopen of ongeldig. Open de uitnodigingslink opnieuw.',
            ], 400);
        }

        $token = $pending['token'];
        $cohort = Cohort::findByInviteToken($token);

        // Re-check: the link may have been revoked or expired while signing in.
        if (! $cohort || ! $cohort->inviteLinkIsUsable()) {
            return $this->invalidLink($cohort);
        }

        if ($request->query('error') || ! is_string($request->query('code'))) {
            return response()->view('join.error', [
                'title' => 'Inloggen geannuleerd',
                'message' => 'Je hebt het inloggen met GitHub geannuleerd. Je kunt het opnieuw proberen via de uitnodigingslink.',
                'retryUrl' => route('join.show', $token),
            ], 400);
        }

        try {
            $userToken = $this->exchangeCode($request->query('code'));
            $githubUser = $this->github->user($userToken)->get('/user')->json();
        } catch (Throwable $e) {
            report($e);

            return response()->view('join.error', [
                'title' => 'Inloggen mislukt',
                'message' => 'We konden je niet aanmelden bij GitHub. Probeer het opnieuw.',
                'retryUrl' => route('join.show', $token),
            ], 502);
        }

        $student = Student::where('cohort_id', $cohort->id)
            ->where('github_user_id', (int) $githubUser['id'])
            ->first();

        if (! $student) {
            Audit::as('student', (string) $githubUser['id'], $githubUser['login'], fn () => Audit::record(
                'join.rejected_not_on_roster',
                $cohort,
                ['github_user_id' => $githubUser['id'], 'login' => $githubUser['login']],
            ));

            return response()->view('join.not-on-roster', [
                'cohort' => $cohort,
                'login' => $githubUser['login'],
            ], 403);
        }

        $result = Audit::asStudent($student, fn () => $joinService->process($student, $userToken, $githubUser));

        unset($userToken);

        $request->session()->regenerate();
        $request->session()->put($this->studentSessionKey($cohort), $student->id);

        return redirect()->route('join.status', $token)->with('join.result', $result->toArray());
    }

    /**
     * Shows the outcome. Refreshing re-checks with server credentials only,
     * so it's safe to repeat.
     */
    public function status(Request $request, JoinService $joinService, string $token)
    {
        $cohort = Cohort::findByInviteToken($token);

        if (! $cohort || $cohort->inviteLinkStatus() === 'none' || $cohort->inviteLinkStatus() === 'revoked' || $cohort->inviteLinkStatus() === 'offboarded') {
            return $this->invalidLink($cohort);
        }

        $studentId = $request->session()->get($this->studentSessionKey($cohort));
        $student = $studentId ? Student::with('group')->where('cohort_id', $cohort->id)->find($studentId) : null;

        if (! $student) {
            return redirect()->route('join.show', $token);
        }

        $result = JoinResult::fromArray($request->session()->get('join.result'));

        if ($result === null) {
            $result = $student->status->value === 'joined'
                ? JoinResult::joined()
                : Audit::asStudent($student, fn () => $joinService->process($student));
        }

        $student->refresh();

        return view('join.status', [
            'cohort' => $cohort,
            'student' => $student,
            'group' => $student->group,
            'result' => $result,
            'token' => $token,
        ]);
    }

    private function exchangeCode(string $code): string
    {
        $response = Http::acceptJson()
            ->asForm()
            ->timeout(15)
            ->post(config('github.oauth.token_url'), [
                'client_id' => config('github.oauth.client_id'),
                'client_secret' => config('github.oauth.client_secret'),
                'code' => $code,
                'redirect_uri' => route('join.callback'),
            ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new GitHubException('OAuth code exchange failed: ' . ($response->json('error_description') ?? $response->json('error') ?? $response->status()));
        }

        return $token;
    }

    private function studentSessionKey(Cohort $cohort): string
    {
        return 'join.student.' . $cohort->id;
    }

    private function invalidLink(?Cohort $cohort)
    {
        $status = $cohort?->inviteLinkStatus();

        return response()->view('join.error', [
            'title' => 'Deze link werkt niet (meer)',
            'message' => match ($status) {
                'expired' => 'Deze uitnodigingslink is verlopen. Vraag je examinator om een nieuwe link.',
                'revoked' => 'Deze uitnodigingslink is ingetrokken. Vraag je examinator om een nieuwe link.',
                'offboarded' => 'Dit examen is afgesloten.',
                default => 'Deze uitnodigingslink bestaat niet. Controleer of je de hele link hebt gekopieerd.',
            },
        ], $cohort ? 410 : 404);
    }
}
