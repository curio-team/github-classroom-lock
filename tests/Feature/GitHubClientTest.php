<?php

namespace Tests\Feature;

use App\GitHub\GitHub;
use App\GitHub\GitHubClient;
use App\GitHub\GitHubException;
use App\GitHub\RateLimitedException;
use App\GitHub\Tokens\GitHubAppInstallationToken;
use App\GitHub\Tokens\StaticToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Tests\TestCase;

class GitHubClientTest extends TestCase
{
    private function client(int $mutationDelayMs = 0): GitHubClient
    {
        return new GitHubClient(new StaticToken('t0k3n'), 'https://api.github.test', '2022-11-28', 3, 10, $mutationDelayMs);
    }

    public function test_sends_auth_and_version_headers(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->client()->get('/orgs/test-org');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.test/orgs/test-org'
            && $request->header('Authorization')[0] === 'Bearer t0k3n'
            && $request->header('X-GitHub-Api-Version')[0] === '2022-11-28'
            && $request->header('Accept')[0] === 'application/vnd.github+json');
    }

    public function test_errors_throw_unless_allowed(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->assertSame(404, $this->client()->get('/repos/x/y', allow: [404])->status());

        try {
            $this->client()->get('/repos/x/y');
            $this->fail('Expected GitHubException');
        } catch (GitHubException $e) {
            $this->assertTrue($e->isNotFound());
            $this->assertSame('GitHub GET /repos/x/y failed with 404: Not Found', $e->getMessage());
        }
    }

    public function test_server_errors_are_retried(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'Bad Gateway'], 502)
            ->push(['id' => 1], 200);

        $this->assertSame(1, $this->client()->get('/x')->json('id'));
        Http::assertSentCount(2);
    }

    public function test_graphql_mutations_are_not_retried_on_server_errors(): void
    {
        // The first attempt may have been applied (e.g. a Project was created), and
        // GitHub won't reject a repeat. The next provisioning run looks it up instead.
        Http::fakeSequence()
            ->push(['message' => 'Bad Gateway'], 502)
            ->push(['data' => ['createProjectV2' => ['projectV2' => ['id' => 'PVT_2']]]]);

        try {
            $this->client()->graphql('mutation CreateProject { createProjectV2(input: {}) { projectV2 { id } } }');
            $this->fail('Expected GitHubException');
        } catch (GitHubException $e) {
            $this->assertSame(502, $e->status);
        }

        Http::assertSentCount(1);
    }

    public function test_graphql_queries_are_retried_on_server_errors(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'Bad Gateway'], 502)
            ->push(['data' => ['viewer' => ['login' => 'bot']]]);

        $this->assertSame('bot', $this->client()->graphql('query Q { viewer { login } }')['viewer']['login']);
        Http::assertSentCount(2);
    }

    public function test_primary_rate_limit_waits_until_reset_when_short(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 3)])
            ->push(['id' => 1], 200);

        $this->assertSame(1, $this->client()->get('/x')->json('id'));
        Sleep::assertSleptTimes(1);
    }

    public function test_secondary_rate_limit_without_headers_waits_a_minute_so_it_is_thrown(): void
    {
        Http::fake(['*' => Http::response(['message' => 'You have exceeded a secondary rate limit'], 403)]);

        try {
            $this->client()->post('/x');
            $this->fail('Expected RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertSame(60, $e->retryAfter);
        }

        Http::assertSentCount(1);
    }

    public function test_a_plain_403_is_not_treated_as_rate_limit(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Must have admin rights to Repository.'], 403)]);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('Must have admin rights');

        try {
            $this->client()->post('/x');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_graphql_errors_are_thrown(): void
    {
        Http::fake(['*' => Http::response(['data' => null, 'errors' => [['message' => 'Could not resolve to a node']]])]);

        $this->expectException(GitHubException::class);
        $this->expectExceptionMessage('GitHub GraphQL error: Could not resolve to a node');

        $this->client()->graphql('query Q { viewer { login } }');
    }

    public function test_mutations_are_spaced_out_to_avoid_secondary_rate_limits(): void
    {
        Http::fake(['*' => Http::response([], 201)]);
        $client = $this->client(mutationDelayMs: 1000);

        $client->get('/a');
        $client->post('/b');
        $client->put('/c');
        $client->graphql('query Q { viewer { login } }');
        $client->graphql('mutation M { x }');

        // Only between the three mutations; GETs and GraphQL queries aren't delayed.
        Sleep::assertSleptTimes(2);
    }

    public function test_a_relative_private_key_path_is_resolved_from_the_project_root(): void
    {
        $config = ['github.app.id' => '123', 'github.app.installation_id' => '42', 'github.app.private_key' => null];

        // Simulate the web server, which runs from public/.
        $cwd = getcwd();
        chdir(public_path());

        try {
            config($config + ['github.app.private_key_path' => 'tests/Fixtures/github-app-test-key.pem']);
            $this->assertInstanceOf(GitHubAppInstallationToken::class, (new GitHub(config('github')))->orgTokenSource());

            config(['github.app.private_key_path' => 'missing.pem']);
            $this->expectExceptionMessage('the private key at ' . base_path('missing.pem') . ' is not readable');
            (new GitHub(config('github')))->orgTokenSource();
        } finally {
            chdir($cwd);
        }
    }

    public function test_github_app_installation_tokens_are_minted_and_cached(): void
    {
        // A throwaway key that only exists for this test.
        $privatePem = file_get_contents(base_path('tests/Fixtures/github-app-test-key.pem'));
        $publicPem = openssl_pkey_get_details(openssl_pkey_get_private($privatePem))['key'];

        Http::fake([
            'api.github.test/app/installations/42/access_tokens' => Http::response([
                'token' => 'ghs_installation',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
            'api.github.test/orgs/test-org' => Http::response(['login' => 'test-org']),
        ]);

        config([
            'github.app.id' => '123',
            'github.app.installation_id' => '42',
            'github.app.private_key' => str_replace("\n", '\n', $privatePem), // as stored in .env
        ]);

        $github = new GitHub(config('github'));
        $github->org()->get('/orgs/test-org');
        $github->org()->get('/orgs/test-org');

        // One token request, signed with the App's key; both API calls used the installation token.
        $tokenRequests = Http::recorded(fn (Request $r) => str_contains($r->url(), 'access_tokens'));
        $this->assertCount(1, $tokenRequests);

        $jwt = (new Parser(new JoseEncoder()))->parse(substr($tokenRequests[0][0]->header('Authorization')[0], 7));
        $this->assertTrue((new Validator())->validate($jwt, new SignedWith(new Sha256(), InMemory::plainText($publicPem))));
        $this->assertSame('123', $jwt->claims()->get('iss'));
        $this->assertLessThanOrEqual(600, $jwt->claims()->get('exp')->getTimestamp() - $jwt->claims()->get('iat')->getTimestamp());

        $apiRequests = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/orgs/test-org'));
        $this->assertCount(2, $apiRequests);
        $this->assertSame('Bearer ghs_installation', $apiRequests[1][0]->header('Authorization')[0]);

        $this->assertInstanceOf(GitHubAppInstallationToken::class, $github->orgTokenSource());
    }
}
