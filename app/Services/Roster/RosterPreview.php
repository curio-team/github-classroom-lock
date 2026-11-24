<?php

namespace App\Services\Roster;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The result of validating a roster file, before anything is saved.
 */
class RosterPreview
{
    public function __construct(
        public readonly int $cohortId,
        /** @var array<int, array{line: int, github_user_id: int, github_login: string, group: string, group_slug: string}> */
        public readonly array $rows,
        /** @var array<int, array{line: ?int, message: string}> */
        public readonly array $errors,
        /** @var array<int, array{line: ?int, message: string}> */
        public readonly array $warnings,
        /** @var array{new: array, moved: array, unchanged: array, removed: array} */
        public readonly array $changes,
        /** @var array<int, string> */
        public readonly array $newGroups,
        /** @var array<int, array{name: string, provisioned: bool}> */
        public readonly array $emptyGroups,
        /** @var array<string, int> */
        public readonly array $groupCounts,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Stores the preview server-side so the admin can confirm it in a later request.
     */
    public function store(): string
    {
        $key = (string) Str::uuid();

        // Stored as a plain array, so it works even when the cache restricts
        // which classes may be unserialized (cache.serializable_classes).
        Cache::put('roster-preview:' . $key, get_object_vars($this), now()->addHour());

        return $key;
    }

    public static function retrieve(?string $key): ?self
    {
        if (! $key) {
            return null;
        }

        $data = Cache::get('roster-preview:' . $key);

        return is_array($data) ? new self(...$data) : null;
    }

    public static function forget(?string $key): void
    {
        if ($key) {
            Cache::forget('roster-preview:' . $key);
        }
    }
}
