<?php

namespace Tests\Feature;

use App\Enums\StudentStatus;
use App\Models\AuditLog;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeGitHub;
use Tests\TestCase;

/**
 * End-to-end tests of the invite link, driven through the HTTP routes like a
 * browser would, against the simulated GitHub API.
 */
class JoinFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakeGitHub $github;

    protected function setUp(): void
    {
        parent::setUp();

        $this->github = $this->fakeGitHub();
    }

    /**
     * Opens the invite link, signs in with GitHub as $login and lands on the status page.
     */
    private function joinAs(string $token, string $login): TestResponse
    {
        $this->get("/join/{$token}")->assertOk()->assertSee('Inloggen met GitHub');

        $redirect = $this->post("/join/{$token}/github")->assertRedirect();
        $location = $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://github.test/login/oauth/authorize?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame(route('join.callback'), $query['redirect_uri']);
        $this->assertSame('write:org', $query['scope']);

        return $this->get('/join/github/callback?' . http_build_query(['code' => 'code-' . $login, 'state' => $query['state']]));
    }

    private function cohortWithLink(array $students = ['alice' => 'Groep 1', 'bob' => 'Groep 2']): array
    {
        $cohort = $this->provisionedCohort($this->github, $students);
        $token = $cohort->generateInviteLink();
        $this->github->log = [];

        return [$cohort, $token];
    }

    /*
     * Invitation flow
     */

    public function test_student_is_invited_accepts_automatically_and_lands_in_their_team(): void
    {
        [$cohort, $token] = $this->cohortWithLink();

        $response = $this->joinAs($token, 'alice');
        $response->assertRedirect("/join/{$token}/status");

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee('Je bent toegevoegd!')
            ->assertSee('https://github.com/test-org/sd1a-2026-groep-1')
            ->assertSee('https://github.com/orgs/test-org/projects/1')
            ->assertDontSee('sd1a-2026-groep-2');

        // Invitation created with server token, with the numeric ID and the group's team.
        $invite = $this->github->requests('POST /orgs/test-org/invitations')[0];
        $this->assertSame(FakeGitHub::ORG_TOKEN, $invite['token']);
        $alice = Student::where('github_login', 'alice')->first();
        $this->assertSame([
            'invitee_id' => $alice->github_user_id,
            'role' => 'direct_member',
            'team_ids' => [$alice->group->team_id],
        ], $invite['data']);

        // Accepted with the student's own token.
        $accept = $this->github->requests('PATCH /user/memberships/orgs/test-org')[0];
        $this->assertSame('user-token-alice', $accept['token']);
        $this->assertSame(['state' => 'active'], $accept['data']);

        // Only her own team: so only her own repository and project.
        $this->assertSame('active', $this->github->orgMembershipState('alice'));
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));
        $this->assertNull($this->github->teamMembershipState('sd1a-2026-groep-2', 'alice'));

        $alice->refresh();
        $this->assertSame(StudentStatus::Joined, $alice->status);
        $this->assertNotNull($alice->invited_at);
        $this->assertNotNull($alice->joined_at);

        // The user token is only used for /user and accepting.
        foreach ($this->github->log as $request) {
            if ($request['token'] === 'user-token-alice') {
                $this->assertContains($request['method'] . ' ' . $request['path'], ['GET /user', 'PATCH /user/memberships/orgs/test-org']);
            }
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'org.invitation_created', 'actor_type' => 'student', 'actor_name' => 'alice']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.joined']);
    }

    public function test_the_student_token_is_never_stored(): void
    {
        [, $token] = $this->cohortWithLink();

        $this->joinAs($token, 'alice');

        $this->assertStringNotContainsString('user-token-alice', serialize(session()->all()));

        foreach (DB::select("select name from sqlite_master where type = 'table'") as $table) {
            $rows = json_encode(DB::table($table->name)->get());
            $this->assertStringNotContainsString('user-token-alice', $rows, "Token found in table {$table->name}");
        }
    }

    public function test_repeating_the_flow_does_not_create_duplicate_invitations_or_memberships(): void
    {
        [, $token] = $this->cohortWithLink();

        $this->joinAs($token, 'alice');
        $this->joinAs($token, 'alice');
        $this->get("/join/{$token}/status")->assertOk()->assertSee('Je bent toegevoegd!');
        $this->get("/join/{$token}/status")->assertOk();

        $this->assertSame(1, $this->github->count('POST /orgs/test-org/invitations'));
        $this->assertSame(1, $this->github->count('PATCH /user/memberships/orgs/test-org'));
        $this->assertSame(0, $this->github->count('PUT /orgs/test-org/teams/.*/memberships/.*'));
        $this->assertSame(1, AuditLog::where('action', 'student.joined')->count());
    }

    public function test_a_refresh_after_a_partial_failure_resumes(): void
    {
        [, $token] = $this->cohortWithLink();

        // Accepting works, but the membership check afterwards fails.
        $this->github->failNext('GET /orgs/test-org/teams/sd1a-2026-groep-1/memberships/alice', 500, times: 3);

        $this->followRedirects($this->joinAs($token, 'alice'))->assertSee('Dat lukte niet');
        $this->assertSame(StudentStatus::Failed, Student::where('github_login', 'alice')->first()->status);

        // Refreshing the status page retries with server credentials only.
        $this->get("/join/{$token}/status")->assertOk()->assertSee('Je bent toegevoegd!');

        $this->assertSame(StudentStatus::Joined, Student::where('github_login', 'alice')->first()->status);
        $this->assertSame(1, $this->github->count('POST /orgs/test-org/invitations'));
    }

    public function test_asynchronous_acceptance_202_shows_processing_and_completes_on_refresh(): void
    {
        [, $token] = $this->cohortWithLink();
        $this->github->acceptStatus = 202;
        // Still pending during the polling in the callback request.
        $this->github->asyncChecksRemaining = config('github.membership_poll.attempts');

        $this->followRedirects($this->joinAs($token, 'alice'))
            ->assertOk()
            ->assertSee('Even geduld')
            ->assertSee('<meta http-equiv="refresh" content="5">', false);

        $this->assertSame(StudentStatus::Invited, Student::where('github_login', 'alice')->first()->status);

        $this->get("/join/{$token}/status")->assertOk()->assertSee('Je bent toegevoegd!');
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));
        $this->assertSame(1, $this->github->count('PATCH /user/memberships/orgs/test-org'));
    }

    public function test_when_automatic_acceptance_fails_the_invitation_link_is_shown(): void
    {
        [, $token] = $this->cohortWithLink();
        $this->github->acceptStatus = 403;

        $this->followRedirects($this->joinAs($token, 'alice'))
            ->assertOk()
            ->assertSee('Nog één stap')
            ->assertSee('https://github.com/orgs/test-org/invitation');

        $alice = Student::where('github_login', 'alice')->first();
        $this->assertSame(StudentStatus::Invited, $alice->status);
        $this->assertStringContainsString('Automatisch accepteren mislukt (403', $alice->failure_reason);

        // The student accepts on github.com; the next refresh completes the join without a new invitation.
        $this->github->orgMembers[$alice->github_user_id]['state'] = 'active';
        $this->github->teams['sd1a-2026-groep-1']['members'][$alice->github_user_id] = 'active';

        $this->get("/join/{$token}/status")->assertSee('Je bent toegevoegd!');
        $this->assertSame(1, $this->github->count('POST /orgs/test-org/invitations'));
    }

    public function test_existing_org_members_are_added_to_their_team_without_an_invitation(): void
    {
        [, $token] = $this->cohortWithLink();
        $this->github->makeOrgMember('alice');

        $this->followRedirects($this->joinAs($token, 'alice'))->assertSee('Je bent toegevoegd!');

        $this->assertSame(0, $this->github->count('POST /orgs/test-org/invitations'));
        $this->assertSame(1, $this->github->count('PUT /orgs/test-org/teams/sd1a-2026-groep-1/memberships/alice'));
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));
    }

    public function test_an_expired_invitation_is_replaced(): void
    {
        [, $token] = $this->cohortWithLink();
        $this->github->acceptStatus = 403;
        $this->joinAs($token, 'alice');

        // Seven days later GitHub has expired the pending invitation.
        $aliceId = $this->github->user('alice')['id'];
        unset($this->github->orgMembers[$aliceId]);
        $this->github->invitations = [];
        $this->github->acceptStatus = 200;

        $this->followRedirects($this->joinAs($token, 'alice'))->assertSee('Je bent toegevoegd!');

        $this->assertSame(2, $this->github->count('POST /orgs/test-org/invitations'));
    }

    public function test_students_without_2fa_are_told_to_enable_it_when_the_org_requires_it(): void
    {
        [, $token] = $this->cohortWithLink();
        $this->github->org2fa = true;
        $this->github->users['alice']['two_factor_authentication'] = false;

        $this->followRedirects($this->joinAs($token, 'alice'))
            ->assertSee('tweestapsverificatie')
            ->assertSee('https://github.com/settings/security');

        $this->assertSame([], $this->github->mutations());
        $this->assertSame(StudentStatus::Failed, Student::where('github_login', 'alice')->first()->status);
    }

    public function test_the_daily_invitation_limit_is_respected(): void
    {
        [, $token] = $this->cohortWithLink();
        config(['github.limits.invitations_per_day' => 1]);
        AuditLog::create(['actor_type' => 'system', 'action' => 'org.invitation_created']);

        $this->followRedirects($this->joinAs($token, 'alice'))->assertSee('Dat lukte niet');

        $this->assertSame(0, $this->github->count('POST /orgs/test-org/invitations'));
        $this->assertStringContainsString('Invitation limit reached', Student::where('github_login', 'alice')->first()->failure_reason);
    }

    /*
     * Who can join, and with which links
     */

    public function test_students_not_on_the_roster_cannot_join_and_nothing_changes_on_github(): void
    {
        [$cohort, $token] = $this->cohortWithLink();
        $this->github->addUser('mallory');

        $response = $this->joinAs($token, 'mallory');

        $response->assertForbidden()->assertSee('Je staat niet op de lijst')->assertSee('mallory');
        $this->assertSame([], $this->github->mutations());
        $this->assertNull($this->github->orgMembershipState('mallory'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'join.rejected_not_on_roster', 'cohort_id' => $cohort->id]);

        // Even with a session for another cohort member, the status page doesn't help.
        $this->get("/join/{$token}/status")->assertRedirect("/join/{$token}");
    }

    public function test_a_student_of_another_cohort_cannot_use_this_link(): void
    {
        [, $token] = $this->cohortWithLink(['alice' => 'Groep 1']);
        $other = $this->makeCohort(['name' => 'Other', 'slug' => 'other']);
        $this->github->addUser('zed');
        $this->importRoster($other, "github_username,group\nzed,X\n");

        $this->joinAs($token, 'zed')->assertForbidden();
        $this->assertSame([], $this->github->mutations());
    }

    public function test_unknown_expired_and_revoked_links_cannot_be_used(): void
    {
        [$cohort, $token] = $this->cohortWithLink();

        $this->get('/join/' . str_repeat('x', 48))->assertNotFound();
        $this->post('/join/' . str_repeat('x', 48) . '/github')->assertNotFound();

        $cohort->forceFill(['invite_expires_at' => now()->subMinute()])->save();
        $this->get("/join/{$token}")->assertStatus(410)->assertSee('verlopen');
        $this->post("/join/{$token}/github")->assertStatus(410);

        $cohort->forceFill(['invite_expires_at' => now()->addDay()])->save();
        $cohort->revokeInviteLink();
        $this->get("/join/{$token}")->assertStatus(410)->assertSee('ingetrokken');

        // A regenerated link invalidates the old one.
        $newToken = $cohort->generateInviteLink();
        $this->get("/join/{$token}")->assertNotFound();
        $this->get("/join/{$newToken}")->assertOk();

        $this->assertSame([], $this->github->log);
    }

    public function test_a_link_revoked_during_sign_in_is_rejected_at_the_callback(): void
    {
        [$cohort, $token] = $this->cohortWithLink();

        $redirect = $this->post("/join/{$token}/github");
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $cohort->revokeInviteLink();

        $this->get('/join/github/callback?' . http_build_query(['code' => 'code-alice', 'state' => $query['state']]))
            ->assertStatus(410);
        $this->assertSame([], $this->github->log);
    }

    public function test_the_oauth_state_must_match(): void
    {
        [, $token] = $this->cohortWithLink();
        $this->post("/join/{$token}/github");

        $this->get('/join/github/callback?code=code-alice&state=forged')->assertStatus(400);
        $this->get('/join/github/callback?code=code-alice')->assertStatus(400);

        $this->assertSame([], $this->github->log);
    }

    public function test_a_cancelled_sign_in_shows_a_retry_link(): void
    {
        [, $token] = $this->cohortWithLink();
        $redirect = $this->post("/join/{$token}/github");
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get('/join/github/callback?' . http_build_query(['error' => 'access_denied', 'state' => $query['state']]))
            ->assertStatus(400)
            ->assertSee('geannuleerd')
            ->assertSee("/join/{$token}", false);
    }

    /*
     * Robustness
     */

    public function test_groups_that_are_not_provisioned_yet_give_a_clear_message_and_no_changes(): void
    {
        $this->github->addUser('alice');
        $cohort = $this->makeCohort();
        $this->importRoster($cohort, "github_username,group\nalice,Groep 1\n");
        $token = $cohort->generateInviteLink();
        $this->github->log = [];

        $this->followRedirects($this->joinAs($token, 'alice'))->assertSee('Je groep is nog niet klaar');

        $this->assertSame([], $this->github->mutations());
    }

    public function test_a_changed_username_is_picked_up_from_the_numeric_id(): void
    {
        [, $token] = $this->cohortWithLink();
        $this->github->renameUser('alice', 'alice-renamed');

        $this->followRedirects($this->joinAs($token, 'alice-renamed'))->assertSee('Je bent toegevoegd!');

        $student = Student::where('github_user_id', $this->github->user('alice-renamed')['id'])->first();
        $this->assertSame('alice-renamed', $student->github_login);
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-1', 'alice-renamed'));
        $this->assertSame(0, $this->github->count('\w+ .*/alice'), 'The old username must not be used anymore');
        $this->assertDatabaseHas('audit_logs', ['action' => 'student.login_changed']);
    }

    public function test_concurrent_attempts_are_serialized(): void
    {
        [, $token] = $this->cohortWithLink();
        $alice = Student::where('github_login', 'alice')->first();

        $lock = Cache::lock('join-student:' . $alice->id, 120);
        $lock->get();

        $this->followRedirects($this->joinAs($token, 'alice'))->assertSee('Er loopt al een poging');
        $this->assertSame([], $this->github->mutations());

        $lock->release();
    }

    public function test_a_student_moved_to_another_group_is_removed_from_the_old_team(): void
    {
        [$cohort, $token] = $this->cohortWithLink(['alice' => 'Groep 1', 'bob' => 'Groep 2']);
        $this->followRedirects($this->joinAs($token, 'alice'))->assertSee('Je bent toegevoegd!');
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));

        $this->importRoster($cohort, "github_username,group\nalice,Groep 2\nbob,Groep 2\n");
        $this->get("/join/{$token}/status")->assertSee('Je bent toegevoegd!');

        $this->assertNull($this->github->teamMembershipState('sd1a-2026-groep-1', 'alice'));
        $this->assertSame('active', $this->github->teamMembershipState('sd1a-2026-groep-2', 'alice'));
        $this->assertNull(Student::where('github_login', 'alice')->first()->previous_group_id);
    }
}
