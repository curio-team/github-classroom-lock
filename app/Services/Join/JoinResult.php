<?php

namespace App\Services\Join;

/**
 * What to show the student after (an attempt at) joining.
 */
class JoinResult
{
    public const JOINED = 'joined';

    /** GitHub is still processing; refreshing the page will check again. */
    public const PROCESSING = 'processing';

    /** The student has to do something (accept the invitation, enable 2FA, ...). */
    public const ACTION_REQUIRED = 'action_required';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $state,
        public readonly string $message,
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionLabel = null,
    ) {
    }

    public static function joined(): self
    {
        return new self(self::JOINED, 'Je bent toegevoegd aan de organisatie en je team.');
    }

    public static function processing(string $message): self
    {
        return new self(self::PROCESSING, $message);
    }

    public static function actionRequired(string $message, ?string $url = null, ?string $label = null): self
    {
        return new self(self::ACTION_REQUIRED, $message, $url, $label);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }

    public function isJoined(): bool
    {
        return $this->state === self::JOINED;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || ! isset($data['state'], $data['message'])) {
            return null;
        }

        return new self($data['state'], $data['message'], $data['actionUrl'] ?? null, $data['actionLabel'] ?? null);
    }
}
