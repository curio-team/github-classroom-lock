<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('chat.model_mini', 'gpt-4o-mini');
        $this->migrator->add('chat.model_advanced', 'gpt-4o');
    }
};
