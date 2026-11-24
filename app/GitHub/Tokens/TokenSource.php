<?php

namespace App\GitHub\Tokens;

interface TokenSource
{
    /**
     * Returns a token usable as `Authorization: Bearer <token>`.
     */
    public function token(): string;

    /**
     * Short description for diagnostics (never includes the token).
     */
    public function describe(): string;
}
