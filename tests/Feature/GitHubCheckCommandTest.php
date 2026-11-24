<?php

namespace Tests\Feature;

use App\GitHub\GitHub;
use Tests\TestCase;

class GitHubCheckCommandTest extends TestCase
{
    public function test_reports_settings_without_making_changes(): void
    {
        $github = $this->fakeGitHub();
        $github->addUser('alice');
        $github->makeOrgMember('alice');

        $this->artisan('github:check', ['--user' => 'alice'])
            ->expectsOutputToContain('✓ Organization readable')
            ->expectsOutputToContain("Set the base permission to 'No permission'")
            ->expectsOutputToContain('✓ Can list invitations (owner)')
            ->expectsOutputToContain('Callback URL to register on GitHub: http://localhost/join/github/callback')
            ->expectsOutputToContain('Org membership: active (role: member)')
            ->assertSuccessful();

        $this->assertSame([], $github->mutations());
    }

    public function test_fails_with_bad_credentials(): void
    {
        $this->fakeGitHub();
        config(['github.token' => 'expired']);
        $this->app->forgetInstance(GitHub::class);

        $this->artisan('github:check')
            ->expectsOutputToContain('401: Bad credentials')
            ->assertFailed();
    }
}
