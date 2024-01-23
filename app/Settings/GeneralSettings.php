<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public bool $chat_active;

    public static function group(): string
    {
        return 'general';
    }
}
