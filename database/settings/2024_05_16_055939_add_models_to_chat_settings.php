<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('chat.model_gpt3', 'gpt-3.5-turbo-0125');
        $this->migrator->add('chat.model_gpt4', 'gpt-4-0125-preview');
    }
};
