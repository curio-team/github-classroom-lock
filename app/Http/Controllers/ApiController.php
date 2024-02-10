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
        return json_encode([
            'content' => $answer
        ]) . "\n\n";
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

        $apiKey = env('OPENAI_API_KEY');
        $history = $request->input('history');

        $promptMessage = $history[count($history) - 1];

        if ($promptMessage['role'] !== 'user') {
            return $this->fakeAnswerString('Sorry, I didn\'t get your message. Please try again, perhaps first refreshing the page.');
        }

        if ($promptMessage['content'] === null || $promptMessage['content'] === '') {
            return $this->fakeAnswerString('Sorry, I didn\'t get your message. Please try again.');
        }

        user()->registerChatWithModel($model);

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
                'temperature' => 0 // Very low temperature to make the model more deterministic
            ],
        ]);

        $body = $response->getBody();

        $partialChunk = '';
        $chunkCount = 0;

        // Stream the response through and process chunks
        while (!$body->eof()) {
            $chunk = $body->read(1024);
            $text = $partialChunk . $chunk;
            $chunks = explode("\n", $text);

            for ($i = 0; $i < count($chunks) - 1; $i++) {
                if (trim($chunks[$i]) !== '') {
                    $chunkCount++;

                    if (strpos($chunks[$i], 'data: ') === 0) {
                        $dataChunk = json_decode(substr($chunks[$i], 6), true);

                        if (isset($dataChunk['choices'][0]['delta']['content'])) {
                            $content = $dataChunk['choices'][0]['delta']['content'];

                            if ($dataChunk['choices'][0]['finish_reason'] == 'stop') {
                                break;
                            }

                            echo json_encode([
                                'content' => $content,
                            ]) . "\n\n";
                        }
                    }
                }
            }

            // Prepare the partial chunk for the next iteration
            $partialChunk = $chunks[count($chunks) - 1];
        }

        // Register the chat token usage
        $settings = app(ChatSettings::class);
        $settings->used_chat_tokens += $chunkCount;
        $settings->save();
    }
}
