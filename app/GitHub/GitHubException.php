<?php

namespace App\GitHub;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Thrown when GitHub returns an error status, or a GraphQL response contains errors.
 */
class GitHubException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?Response $response = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(string $method, string $path, Response $response): self
    {
        $json = $response->json();
        $message = is_array($json) ? ($json['message'] ?? null) : null;
        $details = [];

        if (is_array($json) && isset($json['errors']) && is_array($json['errors'])) {
            foreach ($json['errors'] as $error) {
                $details[] = is_array($error)
                    ? ($error['message'] ?? $error['code'] ?? json_encode($error))
                    : (string) $error;
            }
        }

        $text = sprintf(
            'GitHub %s %s failed with %d: %s',
            $method,
            $path,
            $response->status(),
            $message ?? $response->reason()
        );

        if ($details) {
            $text .= ' (' . implode('; ', $details) . ')';
        }

        return new self($text, $response->status(), $response, $details);
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * A short, human-readable reason for showing in the admin UI.
     */
    public function reason(): string
    {
        return $this->getMessage();
    }
}
