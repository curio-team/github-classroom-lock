<?php

namespace App\GitHub\Tokens;

use App\GitHub\GitHubException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

/**
 * Mints (and caches) installation access tokens for a GitHub App.
 *
 * Installation tokens are valid for one hour. We cache them until five
 * minutes before they expire.
 */
class GitHubAppInstallationToken implements TokenSource
{
    public function __construct(
        private readonly string $appId,
        private readonly string $installationId,
        #[\SensitiveParameter] private readonly string $privateKey,
        private readonly string $apiUrl,
        private readonly string $apiVersion,
    ) {
    }

    public function token(): string
    {
        $cacheKey = 'github-app-token:' . $this->appId . ':' . $this->installationId;

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->apiUrl)
            ->withToken($this->appJwt())
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => $this->apiVersion,
            ])
            ->post("/app/installations/{$this->installationId}/access_tokens");

        if ($response->failed()) {
            throw GitHubException::fromResponse('POST', "/app/installations/{$this->installationId}/access_tokens", $response);
        }

        $token = (string) $response->json('token');
        $expiresAt = $response->json('expires_at');
        $ttl = $expiresAt
            ? max(60, (new DateTimeImmutable($expiresAt))->getTimestamp() - time() - 300)
            : 3000;

        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * The short-lived JWT that authenticates as the App itself.
     */
    public function appJwt(): string
    {
        $now = time();
        $builder = new Builder(new JoseEncoder(), ChainedFormatter::withUnixTimestampDates());

        return $builder
            ->issuedBy($this->appId)
            // Backdate a minute to allow for clock drift, as GitHub recommends.
            ->issuedAt(new DateTimeImmutable('@' . ($now - 60)))
            ->expiresAt(new DateTimeImmutable('@' . ($now + 540)))
            ->getToken(new Sha256(), InMemory::plainText($this->privateKey))
            ->toString();
    }

    public function describe(): string
    {
        return "GitHub App {$this->appId} (installation {$this->installationId})";
    }
}
