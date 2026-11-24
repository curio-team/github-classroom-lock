<?php

namespace App\GitHub\Tokens;

class StaticToken implements TokenSource
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $token,
        private readonly string $description = 'personal access token',
    ) {
    }

    public function token(): string
    {
        return $this->token;
    }

    public function describe(): string
    {
        return $this->description;
    }
}
