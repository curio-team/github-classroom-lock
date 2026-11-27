<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Organization
    |--------------------------------------------------------------------------
    |
    | The organization that cohorts are onboarded into.
    |
    */

    'organization' => env('GITHUB_ORGANIZATION', 'curio-summatief'),

    'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),

    'api_version' => env('GITHUB_API_VERSION', '2022-11-28'),

    // Where repositories are cloned from for backups: {git_url}/{org}/{repository}.git
    'git_url' => env('GITHUB_GIT_URL', 'https://github.com'),

    /*
    |--------------------------------------------------------------------------
    | Organization (server) credentials
    |--------------------------------------------------------------------------
    |
    | Used for everything inside the organization: teams, repositories,
    | projects, invitations and memberships.
    |
    | Either set GITHUB_TOKEN (a fine-grained or classic personal access token
    | of an organization owner), or configure a GitHub App installed on the
    | organization. When the App is configured it takes precedence.
    |
    */

    'token' => env('GITHUB_TOKEN'),

    'app' => [
        'id' => env('GITHUB_APP_ID'),
        'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
        // Either the PEM contents (with literal "\n" allowed) or a path to the PEM file.
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Student sign-in (OAuth)
    |--------------------------------------------------------------------------
    |
    | Credentials of a GitHub OAuth App, or the client ID/secret of a GitHub
    | App (user-to-server tokens). The callback URL must be set to
    | {APP_URL}/join/github/callback.
    |
    | Scopes are only used by OAuth Apps (GitHub Apps ignore them). Accepting
    | an organization invitation (PATCH /user/memberships/orgs/{org}) changes
    | the student's organization membership, which for OAuth Apps falls under
    | `write:org`. If the scope turns out to be insufficient, students simply
    | get the manual "accept invitation" link instead.
    |
    | The student's token is only used during the request that handles the
    | callback and is never stored.
    |
    */

    'oauth' => [
        'client_id' => env('GITHUB_OAUTH_CLIENT_ID'),
        'client_secret' => env('GITHUB_OAUTH_CLIENT_SECRET'),
        'scopes' => env('GITHUB_OAUTH_SCOPES', 'write:org'),
        'authorize_url' => env('GITHUB_OAUTH_AUTHORIZE_URL', 'https://github.com/login/oauth/authorize'),
        'token_url' => env('GITHUB_OAUTH_TOKEN_URL', 'https://github.com/login/oauth/access_token'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provisioning defaults
    |--------------------------------------------------------------------------
    */

    'provisioning' => [
        // Permission the group team gets on its repository: pull, triage, push, maintain, admin.
        'repository_permission' => env('GITHUB_REPOSITORY_PERMISSION', 'push'),

        // Role the group team gets on its Project: READER, WRITER, ADMIN.
        'project_role' => env('GITHUB_PROJECT_ROLE', 'WRITER'),

        // Team privacy: 'closed' (visible to org members) or 'secret'.
        'team_privacy' => env('GITHUB_TEAM_PRIVACY', 'closed'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits and retries
    |--------------------------------------------------------------------------
    */

    'limits' => [
        // Invitations per 24 hours: 500 for orgs older than a month or on a paid plan, else 50.
        'invitations_per_day' => (int) env('GITHUB_INVITATIONS_PER_DAY', 500),

        // Pending invitations expire after this many days on GitHub.
        'invitation_expiry_days' => 7,

        // Default lifetime of a cohort invite link.
        'invite_link_days' => (int) env('INVITE_LINK_DAYS', 14),
    ],

    'retry' => [
        // How many times a request is retried after a (secondary) rate limit or 5xx.
        'max_attempts' => (int) env('GITHUB_RETRY_MAX_ATTEMPTS', 3),

        // Wait at most this many seconds inline. Longer waits are thrown as a
        // RateLimitedException so queued jobs can be released and retried later.
        'max_inline_wait' => (int) env('GITHUB_RETRY_MAX_INLINE_WAIT', 10),

        // GitHub recommends pausing at least one second between mutating
        // requests to avoid secondary rate limits. Applies to the server
        // credentials (not to the student's token).
        'mutation_delay_ms' => (int) env('GITHUB_MUTATION_DELAY_MS', 1000),
    ],

    /*
     * Restoring issue assignments after unlocking (see IssueAssignments):
     * how often to check that everyone is assigned again, and how many
     * seconds to wait between checks.
     */
    'assignments' => [
        'attempts' => (int) env('GITHUB_ASSIGNMENT_ATTEMPTS', 3),
        'verify_delay' => (int) env('GITHUB_ASSIGNMENT_VERIFY_DELAY', 60),
    ],

    /*
     * After a student accepts their invitation, poll this many times for the
     * organization membership to become active before telling them to refresh.
     */
    'membership_poll' => [
        'attempts' => (int) env('GITHUB_MEMBERSHIP_POLL_ATTEMPTS', 3),
        'sleep_ms' => (int) env('GITHUB_MEMBERSHIP_POLL_SLEEP_MS', 1000),
    ],

];
