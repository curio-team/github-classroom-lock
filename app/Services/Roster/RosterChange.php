<?php

namespace App\Services\Roster;

/**
 * The outcome of a change made with the RosterEditor, for the examinator.
 */
class RosterChange
{
    public function __construct(
        /** False when the examinator still has something to do or check. */
        public readonly bool $complete,
        public readonly string $message,
    ) {
    }

    public static function done(string $message): self
    {
        return new self(true, $message);
    }

    public static function attention(string $message): self
    {
        return new self(false, $message);
    }

    /**
     * done() without notes, attention() with them.
     *
     * @param  array<int, string>  $notes
     */
    public static function withNotes(string $message, array $notes): self
    {
        $notes = array_values(array_filter($notes));

        return new self($notes === [], trim($message . ' ' . implode(' ', $notes)));
    }
}
