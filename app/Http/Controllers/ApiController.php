<?php

namespace App\Http\Controllers;

use App\Settings\ChatSettings;
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

    private function fakeAnswerString(string $answer)
    {
        return 'data: ' . json_encode([
            'choices' => [
                [
                    'delta' => [
                        'content' => $answer
                    ]
                ]
            ]
        ]) . "\n\ndata: [DONE]";
    }

    public function performPrompt(Request $request, ChatSettings $settings)
    {
        if (!$settings->chat_active) {
            return $this->fakeAnswerString('The chat is currently disabled. Please contact your teacher about it.');
        }

        $model = self::getModelId($request->input('model'));

        if (!user()->canChatWithModel($model)) {
            return $this->fakeAnswerString('You do not have enough chat tokens to prompt '. $model .'. Try again later.');
        }

        user()->registerChatWithModel($model);

        $apiKey = env('OPENAI_API_KEY');
        $history = $request->input('history');
        // $prompt = $request->input('prompt'); // Is already in the history

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
