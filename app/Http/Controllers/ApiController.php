<?php

namespace App\Http\Controllers;

use App\Settings\ChatSettings;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $history = $request->input('history');

        $promptMessage = $history[count($history) - 1];

        if ($promptMessage['role'] !== 'user') {
            return $this->fakeAnswerString('Sorry, I didn\'t get your message. Please try again, perhaps first refreshing the page.');
        }

        if ($promptMessage['content'] === '') {
            return $this->fakeAnswerString('Sorry, I didn\'t get your message. Please try again.');
        }

        user()->registerChatWithModel($model);

        // Flush whatever is in the output buffer so we can immediately send fully formed JSON responses
        @ob_end_flush();

        return new StreamedResponse(function () use ($request, $model, $history) {
            $apiKey = env('OPENAI_API_KEY');
            $client = new Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'stream' => true
            ]);

            try {
                $apiResponse = $client->post('https://api.openai.com/v1/chat/completions', [
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

                $body = $apiResponse->getBody();

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

                                    // Force the response to be sent to the client (to avoid buffering)
                                    @flush();
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
            } catch (RequestException $e) {
                $statusCode = $e->getResponse()->getStatusCode();

                if ($statusCode === 400) {
                    echo json_encode([
                        'content' => 'Sorry, I couldn\'t get a response from the AI. It seems that the token limit has been reached. Please refresh the page to start a new chat.',
                        'error' => $e->getMessage(),
                        'history' => $history
                    ]) . "\n\n";
                } else {
                    echo json_encode([
                        'content' => 'Sorry, I couldn\'t get a response from the AI. Please try again later or refresh the page to start a new chat.',
                        'error' => $e->getMessage(),
                        'history' => $history
                    ]) . "\n\n";
                }
            }
        });
    }
}
