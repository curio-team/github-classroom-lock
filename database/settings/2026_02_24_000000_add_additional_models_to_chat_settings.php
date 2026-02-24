<?php

use App\Settings\ChatSettings;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Read existing model_mini / model_advanced values (JSON-encoded strings in the settings table)
        $modelMiniPayload = DB::table('settings')->where('name', 'chat.model_mini')->value('payload');
        $modelAdvancedPayload = DB::table('settings')->where('name', 'chat.model_advanced')->value('payload');

        $modelMiniId = $modelMiniPayload ? json_decode($modelMiniPayload, true) : 'gpt-4o-mini';
        $modelAdvancedId = $modelAdvancedPayload ? json_decode($modelAdvancedPayload, true) : 'gpt-4o';

        // Read existing per-model token limits
        $tokenLimitsPayload = DB::table('settings')
            ->where('name', 'chat.max_user_chat_tokens_per_model_per_day')
            ->value('payload');
        $tokenLimits = $tokenLimitsPayload
            ? json_decode($tokenLimitsPayload, true)
            : ChatSettings::getDefaultMaxUserChatTokensPerModelPerDay();

        $models = [
            ['name' => 'mini',     'model_id' => $modelMiniId,     'token_limit' => $tokenLimits['mini']     ?? -1],
            ['name' => 'advanced', 'model_id' => $modelAdvancedId, 'token_limit' => $tokenLimits['advanced'] ?? 46300],
        ];

        $this->migrator->add('chat.models', $models);

        if ($modelMiniPayload !== null) {
            $this->migrator->delete('chat.model_mini');
        }

        if ($modelAdvancedPayload !== null) {
            $this->migrator->delete('chat.model_advanced');
        }
    }
};
