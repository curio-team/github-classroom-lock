<?php

namespace App\Http\Controllers;

use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use GuzzleHttp\Client;

class ApiController extends Controller
{
    private static function getModelId(string $model)
    {
        return match ($model) {
            'gpt-4' => 'gpt-4',
            'gpt-3.5' => 'gpt-3.5-turbo',
            default => 'gpt-3.5-turbo',
        };
    }

    public function performPrompt(Request $request, GeneralSettings $settings)
    {
        if (!$settings->chat_active) {
            echo 'data: ' . json_encode([
                'choices' => [
                    [
                        'delta' => [
                            'content' => 'The chat is currently disabled. Please contact your teacher about it.'
                        ]
                    ]
                ]
            ]) . "\n\n";

            return 'data: [DONE]';
        }

        $apiKey = env('OPENAI_API_KEY');
        $model = self::getModelId($request->input('model'));
        $history = $request->input('history');
        $prompt = $request->input('prompt');

        $client = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'stream' => true
        ]);

        $response = $client->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'messages' => $history,
                'stream' => true,
                'temperature' => 0
            ],
        ]);

        $body = $response->getBody();

        // Stream the response through to the client
        while (!$body->eof()) {
            echo $body->read(1024);
        }
    }
}
