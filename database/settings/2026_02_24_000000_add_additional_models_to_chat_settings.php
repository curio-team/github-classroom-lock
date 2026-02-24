<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $models = [
            [
                'name' => 'gpt-5-mini',
                'model_id' => 'gpt-5-mini',
                'token_limit' => -1
            ],
            [
                'name' => 'gpt-5.1',
                'model_id' => 'gpt-5.1',
                'token_limit' => 46300
            ],
            [
                'name' => 'gpt-4o-mini',
                'model_id' => 'gpt-4o-mini',
                'token_limit' => -1
            ],
            [
                'name' => 'gpt-4o',
                'model_id' => 'gpt-4o',
                'token_limit' => 46300
            ],
        ];

        $this->migrator->add('chat.models', $models);

        // Remove the individual model settings, we now let teachers specify any amount of models in the new 'chat.models' setting.
        $this->migrator->delete('chat.model_mini');
        $this->migrator->delete('chat.model_advanced');
    }
};
