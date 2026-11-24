<?php

namespace App\Services;

use App\GitHub\GitHub;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves GitHub usernames to their stable numeric IDs and back.
 */
class GitHubUsers
{
    public function __construct(private readonly GitHub $github)
    {
    }

    /**
     * @return array{id: int, login: string, type: string}|null
     */
    public function findByLogin(string $login): ?array
    {
        $key = 'github-user-login:' . strtolower($login);

        if (($cached = Cache::get($key)) !== null) {
            return $cached ?: null;
        }

        $response = $this->github->org()->get('/users/' . rawurlencode($login), allow: [404]);
        $user = $response->status() === 404 ? false : $this->normalize($response->json());

        Cache::put($key, $user, now()->addHour());

        return $user ?: null;
    }

    /**
     * Looks up the *current* login for a numeric user ID (logins can change).
     *
     * @return array{id: int, login: string, type: string}|null
     */
    public function findById(int $id): ?array
    {
        $response = $this->github->org()->get('/user/' . $id, allow: [404]);

        return $response->status() === 404 ? null : $this->normalize($response->json());
    }

    private function normalize(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'login' => (string) $user['login'],
            'type' => (string) ($user['type'] ?? 'User'),
        ];
    }
}
