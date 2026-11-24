<?php

namespace App\Console\Commands;

use App\GitHub\GitHub;
use App\GitHub\GitHubException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only diagnostics for the configured credentials. Makes no changes on
 * GitHub, so it's safe to run against production.
 */
class GitHubCheck extends Command
{
    protected $signature = 'github:check {--user= : Also show the org membership of this GitHub username}';

    protected $description = 'Check (read-only) that the GitHub credentials and settings work';

    public function handle(GitHub $github): int
    {
        $ok = true;
        $org = $github->organization();

        $this->info("Organization: {$org}");

        try {
            $client = $github->org();
            $this->line('  Credentials: ' . $client->describe());

            $response = $client->get('/orgs/' . rawurlencode($org));
            $data = $response->json();

            $this->line('  ✓ Organization readable');
            $this->line('    OAuth scopes (classic tokens only): ' . ($response->header('X-OAuth-Scopes') ?: '-'));
            $this->line('    Plan: ' . ($data['plan']['name'] ?? 'unknown (not visible: token is probably not an owner)'));

            if (isset($data['plan']['seats'])) {
                $this->line("    Seats: {$data['plan']['filled_seats']} / {$data['plan']['seats']}");
            }

            $permission = $data['default_repository_permission'] ?? null;
            $this->line('    Base repository permission: ' . ($permission ?? 'unknown'));

            if ($permission !== null && $permission !== 'none') {
                $this->warn("    ! Set the base permission to 'No permission' so students only see their own team's repository.");
            }

            $this->line('    2FA required: ' . (($data['two_factor_requirement_enabled'] ?? false) ? 'yes' : 'no'));
            $this->line('    Created: ' . ($data['created_at'] ?? '?') . ' (invitation limit: 500/day if older than a month or paid, else 50)');

            $invitations = $client->get('/orgs/' . rawurlencode($org) . '/invitations', ['per_page' => 1], allow: [403, 404]);
            $this->line($invitations->successful()
                ? '  ✓ Can list invitations (owner)'
                : '  ✗ Cannot list invitations (' . $invitations->status() . '): the server token must belong to an organization owner');
            $ok = $ok && $invitations->successful();
        } catch (Throwable $e) {
            $ok = false;
            $this->error('  ✗ ' . $this->message($e));
        }

        $this->newLine();
        $this->info('Student sign-in (OAuth)');

        if (config('github.oauth.client_id') && config('github.oauth.client_secret')) {
            $this->line('  ✓ Client ID and secret configured');
            $this->line('    Callback URL to register on GitHub: ' . route('join.callback'));
            $this->line('    Scopes requested (OAuth Apps only): ' . (config('github.oauth.scopes') ?: '-'));
        } else {
            $ok = false;
            $this->error('  ✗ GITHUB_OAUTH_CLIENT_ID / GITHUB_OAUTH_CLIENT_SECRET missing: students cannot sign in.');
        }

        if ($login = $this->option('user')) {
            $this->newLine();
            $this->info("User {$login}");

            try {
                $user = $github->org()->get('/users/' . rawurlencode($login))->json();
                $this->line("  ID: {$user['id']}");

                $membership = $github->org()->get('/orgs/' . rawurlencode($org) . '/memberships/' . rawurlencode($login), allow: [404]);

                if ($membership->status() === 404) {
                    $this->line('  Org membership: none');
                } else {
                    $this->line('  Org membership: ' . $membership->json('state') . ' (role: ' . $membership->json('role') . ')');
                }
            } catch (Throwable $e) {
                $this->error('  ✗ ' . $this->message($e));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function message(Throwable $e): string
    {
        return $e instanceof GitHubException ? $e->reason() : $e->getMessage();
    }
}
