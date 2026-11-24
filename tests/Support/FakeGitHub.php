<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small, stateful simulation of the parts of the GitHub REST and GraphQL
 * APIs this app uses. It behaves like GitHub where it matters for our logic:
 * creating something twice fails with 422, team memberships of non-members
 * become pending invitations, and so on.
 *
 * Every request is logged so tests can assert exactly which calls were made.
 */
class FakeGitHub
{
    public const API = 'https://api.github.test';

    public const ORG_TOKEN = 'test-org-token';

    public string $org = 'test-org';

    /** @var array<string, array> lowercase login => user */
    public array $users = [];

    /** @var array<int, array{state: string, role: string}> user ID => membership */
    public array $orgMembers = [];

    /** @var array<int, array{id: int, invitee_id: int, team_ids: array}> */
    public array $invitations = [];

    /** @var array<string, array> slug => team (with members: [userId => state], repos: [name => permission]) */
    public array $teams = [];

    /** @var array<string, array> name => repository */
    public array $repos = [];

    /** @var array<string, array> node ID => project */
    public array $projects = [];

    /** @var array<int, string> template project number => node ID */
    public array $templateProjects = [];

    public string $basePermission = 'read';

    public bool $organizationProjects = true;

    public bool $org2fa = false;

    public int $seats = 100;

    /** Status returned by PATCH /user/memberships/orgs/{org}: 200, 202, 403, ... */
    public int $acceptStatus = 200;

    /** After a 202 accept, the membership stays pending for this many membership checks. */
    public int $asyncChecksRemaining = 0;

    /** @var array<int, array{pattern: string, status: int, body: array, headers: array, times: int}> */
    public array $failures = [];

    /** @var array<int, array{method: string, path: string, op: ?string, token: ?string, data: array}> */
    public array $log = [];

    private int $nextId = 1000;

    private int $nextProjectNumber = 1;

    public static function install(): self
    {
        $fake = new self();

        Http::fake(fn (Request $request) => $fake->handle($request));

        return $fake;
    }

    /*
     * Test setup helpers
     */

    public function addUser(string $login, bool $twoFactor = true, string $type = 'User'): int
    {
        $id = $this->nextId++;

        $this->users[strtolower($login)] = [
            'id' => $id,
            'login' => $login,
            'node_id' => 'U_' . $id,
            'type' => $type,
            'two_factor_authentication' => $twoFactor,
        ];

        return $id;
    }

    public function renameUser(string $from, string $to): void
    {
        $user = $this->users[strtolower($from)];
        unset($this->users[strtolower($from)]);
        $user['login'] = $to;
        $this->users[strtolower($to)] = $user;
    }

    public function makeOrgMember(string $login, string $role = 'member'): void
    {
        $this->orgMembers[$this->user($login)['id']] = ['state' => 'active', 'role' => $role];
    }

    /**
     * Makes the next $times requests matching "METHOD /path" (regex) or a
     * GraphQL operation name fail.
     */
    public function failNext(string $pattern, int $status = 500, array $body = ['message' => 'Server Error'], array $headers = [], int $times = 1): void
    {
        $this->failures[] = compact('pattern', 'status', 'body', 'headers', 'times');
    }

    public function user(string $login): ?array
    {
        return $this->users[strtolower($login)] ?? null;
    }

    /*
     * Assertion helpers
     */

    /**
     * @return array<int, array> requests matching "METHOD /path" regex or a GraphQL operation name
     */
    public function requests(string $pattern): array
    {
        return array_values(array_filter($this->log, fn ($entry) => $this->matches($pattern, $entry)));
    }

    public function count(string $pattern): int
    {
        return count($this->requests($pattern));
    }

    /**
     * Requests that changed something on GitHub (excluding OAuth token exchange).
     */
    public function mutations(): array
    {
        return array_values(array_filter($this->log, function ($entry) {
            if ($entry['op'] !== null) {
                return str_starts_with($entry['op'], 'mutation:');
            }

            return $entry['method'] !== 'GET' && ! str_starts_with($entry['path'], '/login/oauth');
        }));
    }

    public function orgMembershipState(string $login): ?string
    {
        return $this->orgMembers[$this->user($login)['id']]['state'] ?? null;
    }

    public function teamMembershipState(string $teamSlug, string $login): ?string
    {
        return $this->teams[$teamSlug]['members'][$this->user($login)['id']] ?? null;
    }

    /*
     * Request handling
     */

    public function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = rawurldecode($url['path'] ?? '/');
        $method = strtoupper($request->method());
        // Round-trip through JSON, as GitHub would see the body.
        $data = json_decode(json_encode($request->data()), true) ?? [];
        $token = preg_replace('/^Bearer\s+/i', '', $request->header('Authorization')[0] ?? '') ?: null;

        $op = null;

        if ($path === '/graphql') {
            preg_match('/^\s*(query|mutation)\s+(\w+)/', $data['query'] ?? '', $matches);
            $op = isset($matches[2]) ? $matches[1] . ':' . $matches[2] : 'query:anonymous';
        }

        $entry = compact('method', 'path', 'op', 'token', 'data');
        $this->log[] = $entry;

        foreach ($this->failures as &$failure) {
            if ($failure['times'] > 0 && $this->matches($failure['pattern'], $entry)) {
                $failure['times']--;

                return Http::response($failure['body'], $failure['status'], $failure['headers']);
            }
        }
        unset($failure);

        if (($url['host'] ?? '') === 'github.test') {
            return $this->oauth($path, $data);
        }

        if ($op !== null) {
            return $this->graphql($op, $data['variables'] ?? []);
        }

        return $this->rest($method, $path, $data, $token);
    }

    private function matches(string $pattern, array $entry): bool
    {
        if ($entry['op'] !== null && preg_match('/^\w+$/', $pattern)) {
            return str_ends_with($entry['op'], ':' . $pattern);
        }

        return (bool) preg_match('#^' . $pattern . '$#', $entry['method'] . ' ' . $entry['path']);
    }

    private function oauth(string $path, array $data)
    {
        if ($path === '/login/oauth/access_token') {
            $login = substr((string) ($data['code'] ?? ''), strlen('code-'));

            if ($this->user($login) === null) {
                return Http::response(['error' => 'bad_verification_code', 'error_description' => 'The code passed is incorrect or expired.']);
            }

            return Http::response(['access_token' => 'user-token-' . $login, 'token_type' => 'bearer', 'scope' => 'write:org']);
        }

        return Http::response([], 404);
    }

    private function rest(string $method, string $path, array $data, ?string $token)
    {
        $org = preg_quote($this->org, '#');
        $route = $method . ' ' . $path;
        $m = [];

        $isUserToken = $token !== null && str_starts_with($token, 'user-token-');

        // The student's token may only be used for /user and accepting the invitation.
        if ($isUserToken && ! in_array($route, ['GET /user', "PATCH /user/memberships/orgs/{$this->org}"], true)) {
            return Http::response(['message' => 'Resource not accessible by integration'], 403);
        }

        if (! $isUserToken && $token !== self::ORG_TOKEN && ! str_starts_with((string) $token, 'ghs_')) {
            return Http::response(['message' => 'Bad credentials'], 401);
        }

        return match (true) {
            // Users
            $route === 'GET /user' => $this->authenticatedUser($token),
            (bool) preg_match('#^GET /users/([^/]+)$#', $route, $m) => $this->userResponse($this->user($m[1])),
            (bool) preg_match('#^GET /user/(\d+)$#', $route, $m) => $this->userResponse($this->userById((int) $m[1])),

            // Organization
            $route === "GET /orgs/{$this->org}" => Http::response([
                'login' => $this->org,
                'node_id' => 'O_test',
                'default_repository_permission' => $this->basePermission,
                'has_organization_projects' => $this->organizationProjects,
                'two_factor_requirement_enabled' => $this->org2fa,
                'created_at' => '2020-01-01T00:00:00Z',
                'plan' => ['name' => 'enterprise', 'seats' => $this->seats, 'filled_seats' => count(array_filter($this->orgMembers, fn ($m) => $m['state'] === 'active'))],
            ]),
            $route === "PATCH /orgs/{$this->org}" => $this->updateOrg($data),
            $route === "GET /orgs/{$this->org}/invitations" => Http::response(array_values($this->invitations)),
            $route === "POST /orgs/{$this->org}/invitations" => $this->createInvitation($data),
            (bool) preg_match("#^GET /orgs/{$org}/memberships/([^/]+)$#", $route, $m) => $this->orgMembership($m[1]),
            (bool) preg_match("#^DELETE /orgs/{$org}/memberships/([^/]+)$#", $route, $m) => $this->removeOrgMember($m[1]),
            $route === "PATCH /user/memberships/orgs/{$this->org}" => $this->acceptInvitation($token),

            // Teams
            $route === "POST /orgs/{$this->org}/teams" => $this->createTeam($data),
            (bool) preg_match("#^GET /orgs/{$org}/teams/([^/]+)$#", $route, $m) => isset($this->teams[$m[1]])
                ? Http::response($this->teamJson($this->teams[$m[1]]))
                : Http::response(['message' => 'Not Found'], 404),
            (bool) preg_match("#^PUT /orgs/{$org}/teams/([^/]+)/repos/{$org}/([^/]+)$#", $route, $m) => $this->grantRepo($m[1], $m[2], $data),
            (bool) preg_match("#^DELETE /orgs/{$org}/teams/([^/]+)/repos/{$org}/([^/]+)$#", $route, $m) => $this->revokeRepo($m[1], $m[2]),
            (bool) preg_match("#^(GET|PUT|DELETE) /orgs/{$org}/teams/([^/]+)/memberships/([^/]+)$#", $route, $m) => $this->teamMembership($m[1], $m[2], $m[3]),

            // Repositories
            (bool) preg_match("#^GET /repos/{$org}/([^/]+)$#", $route, $m) => isset($this->repos[$m[1]])
                ? Http::response($this->repos[$m[1]])
                : Http::response(['message' => 'Not Found'], 404),
            (bool) preg_match("#^PATCH /repos/{$org}/([^/]+)$#", $route, $m) => $this->updateRepo($m[1], $data),
            $route === "POST /orgs/{$this->org}/repos" => $this->createRepo($data['name'], (bool) ($data['private'] ?? false)),
            (bool) preg_match('#^POST /repos/([^/]+)/([^/]+)/generate$#', $route, $m) => $this->createRepo($data['name'], (bool) ($data['private'] ?? false), "{$m[1]}/{$m[2]}"),

            default => Http::response(['message' => "FakeGitHub: no route for {$route}"], 501),
        };
    }

    /*
     * Users
     */

    private function userById(int $id): ?array
    {
        foreach ($this->users as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }

        return null;
    }

    private function userResponse(?array $user)
    {
        if (! $user) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        return Http::response(array_diff_key($user, ['two_factor_authentication' => true]));
    }

    private function authenticatedUser(?string $token)
    {
        $user = $this->user(substr((string) $token, strlen('user-token-')));

        return $user ? Http::response($user) : Http::response(['message' => 'Bad credentials'], 401);
    }

    /*
     * Organization membership & invitations
     */

    private function updateOrg(array $data)
    {
        if (isset($data['default_repository_permission'])) {
            $this->basePermission = $data['default_repository_permission'];
        }

        if (isset($data['has_organization_projects'])) {
            $this->organizationProjects = (bool) $data['has_organization_projects'];
        }

        return Http::response(['login' => $this->org, 'default_repository_permission' => $this->basePermission]);
    }

    private function createInvitation(array $data)
    {
        $userId = (int) $data['invitee_id'];

        if ($this->userById($userId) === null) {
            return Http::response(['message' => 'Validation Failed', 'errors' => [['message' => 'Invitee is not a valid user']]], 422);
        }

        if (isset($this->orgMembers[$userId])) {
            return Http::response(['message' => 'Validation Failed', 'errors' => [['message' => 'Invitee is already a part of this organization']]], 422);
        }

        $id = $this->nextId++;
        $this->invitations[$id] = ['id' => $id, 'invitee_id' => $userId, 'team_ids' => $data['team_ids'] ?? [], 'role' => $data['role'] ?? 'direct_member'];
        $this->orgMembers[$userId] = ['state' => 'pending', 'role' => 'member'];

        return Http::response(['id' => $id, 'login' => $this->userById($userId)['login'], 'role' => $data['role'] ?? 'direct_member', 'team_count' => count($data['team_ids'] ?? [])], 201);
    }

    private function orgMembership(string $login)
    {
        $user = $this->user($login);
        $membership = $user ? ($this->orgMembers[$user['id']] ?? null) : null;

        if (! $membership) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        if ($membership['state'] === 'accepting') {
            if ($this->asyncChecksRemaining > 0) {
                $this->asyncChecksRemaining--;

                return Http::response(['state' => 'pending', 'role' => 'member']);
            }

            $this->activateInvitation($user['id']);
            $membership = $this->orgMembers[$user['id']];
        }

        return Http::response([
            'state' => $membership['state'],
            'role' => $membership['role'],
            'user' => ['login' => $user['login'], 'id' => $user['id']],
        ]);
    }

    private function removeOrgMember(string $login)
    {
        $user = $this->user($login);

        if (! $user || ! isset($this->orgMembers[$user['id']])) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        unset($this->orgMembers[$user['id']]);
        $this->invitations = array_filter($this->invitations, fn ($invitation) => $invitation['invitee_id'] !== $user['id']);

        foreach ($this->teams as &$team) {
            unset($team['members'][$user['id']]);
        }

        return Http::response(null, 204);
    }

    private function acceptInvitation(?string $token)
    {
        $user = $this->user(substr((string) $token, strlen('user-token-')));
        $membership = $user ? ($this->orgMembers[$user['id']] ?? null) : null;

        if (! $membership || $membership['state'] !== 'pending') {
            return Http::response(['message' => 'Not Found'], 404);
        }

        if ($this->acceptStatus >= 400) {
            return Http::response(['message' => 'You must enable two-factor authentication'], $this->acceptStatus);
        }

        if ($this->acceptStatus === 202) {
            $this->orgMembers[$user['id']]['state'] = 'accepting';

            return Http::response(['state' => 'pending'], 202);
        }

        $this->activateInvitation($user['id']);

        return Http::response(['state' => 'active', 'role' => 'member'], 200);
    }

    private function activateInvitation(int $userId): void
    {
        $this->orgMembers[$userId]['state'] = 'active';

        foreach ($this->invitations as $id => $invitation) {
            if ($invitation['invitee_id'] === $userId) {
                foreach ($this->teams as &$team) {
                    if (in_array($team['id'], $invitation['team_ids'], true) || ($team['members'][$userId] ?? null) === 'pending') {
                        $team['members'][$userId] = 'active';
                    }
                }
                unset($team);

                unset($this->invitations[$id]);
            }
        }
    }

    /*
     * Teams
     */

    private function createTeam(array $data)
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $data['name']));

        if (isset($this->teams[$slug])) {
            return Http::response(['message' => 'Validation Failed', 'errors' => [['message' => 'Name must be unique for this org']]], 422);
        }

        $id = $this->nextId++;
        $this->teams[$slug] = [
            'id' => $id,
            'slug' => $slug,
            'name' => $data['name'],
            'node_id' => 'T_' . $id,
            'privacy' => $data['privacy'] ?? 'secret',
            'members' => [],
            'repos' => [],
        ];

        return Http::response($this->teamJson($this->teams[$slug]), 201);
    }

    private function teamJson(array $team): array
    {
        return array_diff_key($team, ['members' => true, 'repos' => true]);
    }

    private function revokeRepo(string $slug, string $repo)
    {
        if (! isset($this->teams[$slug]['repos'][$repo])) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        unset($this->teams[$slug]['repos'][$repo]);

        return Http::response(null, 204);
    }

    /**
     * The permission a team has on a repository, or null.
     */
    public function teamRepoPermission(string $slug, string $repo): ?string
    {
        return $this->teams[$slug]['repos'][$repo] ?? null;
    }

    private function grantRepo(string $slug, string $repo, array $data)
    {
        if (! isset($this->teams[$slug], $this->repos[$repo])) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        $this->teams[$slug]['repos'][$repo] = $data['permission'] ?? 'pull';

        return Http::response(null, 204);
    }

    private function teamMembership(string $method, string $slug, string $login)
    {
        $user = $this->user($login);

        if (! isset($this->teams[$slug]) || ! $user) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        $current = $this->teams[$slug]['members'][$user['id']] ?? null;

        if ($method === 'GET') {
            return $current ? Http::response(['state' => $current, 'role' => 'member']) : Http::response(['message' => 'Not Found'], 404);
        }

        if ($method === 'DELETE') {
            if (! $current) {
                return Http::response(['message' => 'Not Found'], 404);
            }

            unset($this->teams[$slug]['members'][$user['id']]);

            return Http::response(null, 204);
        }

        // PUT: active for org members; otherwise GitHub sends an org invitation.
        if (($this->orgMembers[$user['id']]['state'] ?? null) === 'active') {
            $this->teams[$slug]['members'][$user['id']] = 'active';
        } else {
            $this->teams[$slug]['members'][$user['id']] = 'pending';
            $this->orgMembers[$user['id']] ??= ['state' => 'pending', 'role' => 'member'];
            $id = $this->nextId++;
            $this->invitations[$id] = ['id' => $id, 'invitee_id' => $user['id'], 'team_ids' => [$this->teams[$slug]['id']], 'role' => 'direct_member'];
        }

        return Http::response(['state' => $this->teams[$slug]['members'][$user['id']], 'role' => 'member']);
    }

    /*
     * Repositories
     */

    private function createRepo(string $name, bool $private, ?string $template = null)
    {
        if (isset($this->repos[$name])) {
            return Http::response(['message' => 'Repository creation failed.', 'errors' => [['message' => 'name already exists on this account']]], 422);
        }

        $id = $this->nextId++;
        $this->repos[$name] = [
            'id' => $id,
            'name' => $name,
            'node_id' => 'R_' . $id,
            'private' => $private,
            'archived' => false,
            'html_url' => "https://github.com/{$this->org}/{$name}",
            'template' => $template,
        ];

        return Http::response($this->repos[$name], 201);
    }

    private function updateRepo(string $name, array $data)
    {
        if (! isset($this->repos[$name])) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        foreach (['private', 'archived'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->repos[$name][$key] = (bool) $data[$key];
            }
        }

        return Http::response($this->repos[$name]);
    }

    /*
     * GraphQL
     */

    private function graphql(string $op, array $variables)
    {
        $data = match ($op) {
            'query:ProjectById' => ['node' => $this->projectNode($variables['id'])],
            'query:FindProjects' => ['organization' => ['projectsV2' => ['nodes' => array_values(array_map(
                fn ($p) => array_intersect_key($p, array_flip(['id', 'number', 'title', 'url', 'closed'])),
                array_filter($this->projects, fn ($p) => str_contains(strtolower($p['title']), strtolower($variables['query']))),
            ))]]],
            'query:TemplateProject' => ['organization' => ['projectV2' => isset($this->templateProjects[$variables['number']])
                ? ['id' => $this->templateProjects[$variables['number']]]
                : null]],
            'mutation:CreateProject', 'mutation:CopyProject' => ! $this->organizationProjects
                ? ['__error' => 'Something went wrong while executing your query on 2026-11-24T11:02:04Z.']
                : ($op === 'mutation:CreateProject'
                    ? ['createProjectV2' => ['projectV2' => $this->createProject($variables['input']['title'])]]
                    : ['copyProjectV2' => ['projectV2' => $this->createProject($variables['input']['title'], $variables['input']['projectId'])]]),
            'query:ProjectLinks' => ['node' => $this->projectLinks($variables['id'])],
            'mutation:LinkProjectToRepository' => $this->link($variables['input']['projectId'], 'repositories', $variables['input']['repositoryId'], 'linkProjectV2ToRepository', 'repository'),
            'mutation:LinkProjectToTeam' => $this->link($variables['input']['projectId'], 'teams', $variables['input']['teamId'], 'linkProjectV2ToTeam', 'team'),
            'mutation:SetProjectCollaborators' => $this->setCollaborators($variables['input']),
            default => null,
        };

        if ($data === null) {
            return Http::response(['errors' => [['message' => "FakeGitHub: unknown operation {$op}"]]], 200);
        }

        if (isset($data['__error'])) {
            return Http::response(['data' => null, 'errors' => [['message' => $data['__error']]]], 200);
        }

        return Http::response(['data' => $data]);
    }

    private function projectNode(string $id): ?array
    {
        $project = $this->projects[$id] ?? null;

        return $project ? array_intersect_key($project, array_flip(['id', 'number', 'url', 'closed'])) : null;
    }

    private function createProject(string $title, ?string $copiedFrom = null): array
    {
        $number = $this->nextProjectNumber++;
        $id = 'PVT_' . $number;

        $this->projects[$id] = [
            'id' => $id,
            'number' => $number,
            'title' => $title,
            'url' => "https://github.com/orgs/{$this->org}/projects/{$number}",
            'closed' => false,
            'copied_from' => $copiedFrom,
            'repositories' => [],
            'teams' => [],
            'collaborators' => [],
        ];

        return ['id' => $id, 'number' => $number, 'url' => $this->projects[$id]['url']];
    }

    private function projectLinks(string $id): ?array
    {
        $project = $this->projects[$id] ?? null;

        if (! $project) {
            return null;
        }

        return [
            'repositories' => ['nodes' => array_map(fn ($id) => ['id' => $id], $project['repositories'])],
            'teams' => ['nodes' => array_map(fn ($id) => ['id' => $id], $project['teams'])],
        ];
    }

    private function link(string $projectId, string $type, string $nodeId, string $field, string $returns): array
    {
        if (! isset($this->projects[$projectId])) {
            return ['__error' => 'Could not resolve to a node with the global id'];
        }

        if (in_array($nodeId, $this->projects[$projectId][$type], true)) {
            return ['__error' => 'Already linked'];
        }

        $this->projects[$projectId][$type][] = $nodeId;

        return [$field => [$returns => ['id' => $nodeId]]];
    }

    private function setCollaborators(array $input): array
    {
        foreach ($input['collaborators'] as $collaborator) {
            $this->projects[$input['projectId']]['collaborators'][$collaborator['teamId'] ?? $collaborator['userId']] = $collaborator['role'];
        }

        return ['updateProjectV2Collaborators' => ['clientMutationId' => null]];
    }
}
