<?php

namespace App\GitHub;

use App\GitHub\Tokens\TokenSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Thin wrapper around Laravel's HTTP client for the GitHub REST and GraphQL APIs.
 *
 * - Throws GitHubException for error responses, except for statuses the caller allows.
 * - Retries (secondary) rate limits and 5xx errors, waiting inline for short
 *   periods and throwing RateLimitedException for longer ones.
 * - Optionally pauses between mutating requests, as GitHub recommends to
 *   avoid secondary rate limits.
 */
class GitHubClient
{
    private ?float $lastMutationAt = null;

    public function __construct(
        private readonly TokenSource $token,
        private readonly string $baseUrl,
        private readonly string $apiVersion,
        private readonly int $maxAttempts = 3,
        private readonly int $maxInlineWait = 10,
        private readonly int $mutationDelayMs = 0,
    ) {
    }

    public function describe(): string
    {
        return $this->token->describe();
    }

    public function get(string $path, array $query = [], array $allow = []): Response
    {
        return $this->send('GET', $path, $query, $allow);
    }

    public function post(string $path, array $data = [], array $allow = []): Response
    {
        return $this->send('POST', $path, $data, $allow);
    }

    public function put(string $path, array $data = [], array $allow = []): Response
    {
        return $this->send('PUT', $path, $data, $allow);
    }

    public function patch(string $path, array $data = [], array $allow = []): Response
    {
        return $this->send('PATCH', $path, $data, $allow);
    }

    public function delete(string $path, array $data = [], array $allow = []): Response
    {
        return $this->send('DELETE', $path, $data, $allow);
    }

    /**
     * Fetches all pages of a list endpoint.
     *
     * @return array<int, array>
     */
    public function paginate(string $path, array $query = [], int $maxPages = 50): array
    {
        $items = [];
        $query = ['per_page' => 100] + $query;

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->get($path, $query + ['page' => $page]);
            $batch = $response->json() ?? [];
            $items = array_merge($items, $batch);

            if (count($batch) < $query['per_page'] || ! str_contains((string) $response->header('Link'), 'rel="next"')) {
                break;
            }
        }

        return $items;
    }

    /**
     * Executes a GraphQL query and returns its `data`.
     *
     * @throws GitHubException when the response contains errors (unless $allowErrors).
     */
    public function graphql(string $query, array $variables = [], bool $allowErrors = false): array
    {
        $isMutation = (bool) preg_match('/^\s*mutation\b/', $query);
        $response = $this->send($isMutation ? 'POST' : 'GRAPHQL', '/graphql', [
            'query' => $query,
            'variables' => (object) $variables,
        ]);

        $errors = $response->json('errors') ?? [];

        if ($errors && ! $allowErrors) {
            $messages = array_map(fn ($error) => $error['message'] ?? json_encode($error), $errors);

            throw new GitHubException(
                'GitHub GraphQL error: ' . implode('; ', $messages),
                $response->status(),
                $response,
                $errors,
            );
        }

        return $response->json('data') ?? [];
    }

    /**
     * @param  string  $method  HTTP method, or GRAPHQL for read-only GraphQL POSTs (not throttled)
     * @param  array<int, int>  $allow  Error statuses that are returned instead of thrown
     */
    public function send(string $method, string $path, array $data = [], array $allow = []): Response
    {
        $httpMethod = $method === 'GRAPHQL' ? 'POST' : $method;
        $isMutation = ! in_array($method, ['GET', 'GRAPHQL'], true);

        // A GraphQL mutation that times out or returns 5xx may still have been
        // applied (e.g. createProjectV2), and GitHub won't reject a repeat like
        // REST does with 422. Don't retry those blindly: the next provisioning
        // run looks the result up first. REST creates are safe to retry.
        $retryOnError = ! ($isMutation && $path === '/graphql');

        if ($isMutation) {
            $this->throttleMutation();
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->request($httpMethod, $path, $data);
            } catch (ConnectionException $e) {
                if ($retryOnError && $attempt < $this->maxAttempts) {
                    Sleep::for($attempt)->seconds();
                    continue;
                }

                throw new GitHubException("GitHub {$httpMethod} {$path} failed: " . $e->getMessage());
            }

            if ($response->successful() || in_array($response->status(), $allow, true)) {
                return $response;
            }

            $retryAfter = $this->rateLimitWait($response);

            if ($retryAfter !== null) {
                if ($attempt < $this->maxAttempts && $retryAfter <= $this->maxInlineWait) {
                    Sleep::for(max(1, $retryAfter))->seconds();
                    continue;
                }

                throw new RateLimitedException(
                    "GitHub rate limit reached on {$httpMethod} {$path}; retry after {$retryAfter} seconds.",
                    $retryAfter,
                    $response->status(),
                );
            }

            if ($retryOnError && $response->serverError() && $attempt < $this->maxAttempts) {
                Sleep::for($attempt)->seconds();
                continue;
            }

            throw GitHubException::fromResponse($httpMethod, $path, $response);
        }
    }

    private function request(string $method, string $path, array $data): Response
    {
        $request = Http::baseUrl($this->baseUrl)
            ->withToken($this->token->token())
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => $this->apiVersion,
                'User-Agent' => 'cohort-onboarding-service',
            ])
            ->timeout(30);

        $path = '/' . ltrim($path, '/');

        return match ($method) {
            'GET' => $request->get($path, $data),
            'DELETE' => $data ? $request->delete($path, $data) : $request->delete($path),
            default => $request->send($method, $path, ['json' => $data ?: (object) []]),
        };
    }

    /**
     * Returns the number of seconds to wait when the response is a (secondary)
     * rate limit, or null when it is a regular error.
     */
    private function rateLimitWait(Response $response): ?int
    {
        $status = $response->status();

        if ($status !== 403 && $status !== 429) {
            return null;
        }

        $retryAfter = $response->header('Retry-After');

        if ($retryAfter !== '' && is_numeric($retryAfter)) {
            return (int) $retryAfter;
        }

        if ($response->header('X-RateLimit-Remaining') === '0') {
            $reset = (int) $response->header('X-RateLimit-Reset');

            return max(1, $reset - time());
        }

        $message = strtolower((string) ($response->json('message') ?? ''));

        if ($status === 429 || str_contains($message, 'secondary rate limit') || str_contains($message, 'rate limit exceeded')) {
            // GitHub: "If no header is present, wait at least one minute before retrying."
            return 60;
        }

        return null;
    }

    private function throttleMutation(): void
    {
        if ($this->mutationDelayMs <= 0) {
            return;
        }

        $now = microtime(true);

        if ($this->lastMutationAt !== null) {
            $elapsedMs = (int) (($now - $this->lastMutationAt) * 1000);

            if ($elapsedMs < $this->mutationDelayMs) {
                Sleep::for($this->mutationDelayMs - $elapsedMs)->milliseconds();
            }
        }

        $this->lastMutationAt = microtime(true);
    }
}
