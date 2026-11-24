<?php

namespace App\GitHub;

/**
 * Thrown when GitHub asks us to back off for longer than we are willing to
 * wait inline. Queued jobs catch this and release themselves for later.
 */
class RateLimitedException extends GitHubException
{
    public function __construct(string $message, public readonly int $retryAfter, int $status = 429)
    {
        parent::__construct($message, $status);
    }
}
