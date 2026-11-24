<?php

namespace App\GitHub;

use App\GitHub\Tokens\GitHubAppInstallationToken;
use App\GitHub\Tokens\StaticToken;
use App\GitHub\Tokens\TokenSource;
use RuntimeException;

/**
 * Builds the API clients for the two kinds of credentials we use:
 *
 * - org():  server credentials for the organization (GitHub App installation, or a PAT)
 * - user(): a student's OAuth token, only during the join request
 */
class GitHub
{
    private ?GitHubClient $org = null;

    public function __construct(private readonly array $config)
    {
    }

    public function organization(): string
    {
        return $this->config['organization'];
    }

    public function org(): GitHubClient
    {
        return $this->org ??= $this->makeClient($this->orgTokenSource(), throttle: true);
    }

    public function user(#[\SensitiveParameter] string $token): GitHubClient
    {
        return $this->makeClient(new StaticToken($token, 'student user token'), throttle: false);
    }

    /**
     * The URL students can use to accept a pending invitation manually.
     */
    public function invitationUrl(): string
    {
        return 'https://github.com/orgs/' . rawurlencode($this->organization()) . '/invitation';
    }

    public function orgTokenSource(): TokenSource
    {
        $app = $this->config['app'] ?? [];

        if (! empty($app['id']) && ! empty($app['installation_id'])) {
            return new GitHubAppInstallationToken(
                (string) $app['id'],
                (string) $app['installation_id'],
                $this->appPrivateKey($app),
                $this->config['api_url'],
                $this->config['api_version'],
            );
        }

        if (empty($this->config['token'])) {
            throw new RuntimeException('No GitHub credentials configured: set the GITHUB_APP_* variables (or GITHUB_TOKEN).');
        }

        return new StaticToken($this->config['token'], 'personal access token (GITHUB_TOKEN)');
    }

    private function appPrivateKey(array $app): string
    {
        if (! empty($app['private_key'])) {
            return str_replace('\n', "\n", $app['private_key']);
        }

        $path = $this->resolvePath($app['private_key_path'] ?? null);

        if ($path !== null && is_readable($path)) {
            return file_get_contents($path);
        }

        throw new RuntimeException($path === null
            ? 'GITHUB_APP_ID is set, but GITHUB_APP_PRIVATE_KEY_PATH is empty.'
            : "GITHUB_APP_ID is set, but the private key at {$path} is not readable (GITHUB_APP_PRIVATE_KEY_PATH).");
    }

    /**
     * Relative paths are relative to the project root, not the working
     * directory (which is public/ under `php artisan serve` and most web servers).
     */
    private function resolvePath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $isAbsolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);

        return $isAbsolute ? $path : base_path($path);
    }

    private function makeClient(TokenSource $token, bool $throttle): GitHubClient
    {
        $retry = $this->config['retry'];

        return new GitHubClient(
            $token,
            $this->config['api_url'],
            $this->config['api_version'],
            $retry['max_attempts'],
            $retry['max_inline_wait'],
            $throttle ? $retry['mutation_delay_ms'] : 0,
        );
    }
}
