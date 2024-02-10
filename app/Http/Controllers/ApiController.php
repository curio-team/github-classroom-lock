<?php

namespace App\Http\Controllers;

use App\Settings\ChatSettings;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yethee\Tiktoken\EncoderProvider;

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

    private static function getModelTokenLimit(string $model)
    {
        $summaryLength = strlen(json_encode(static::getSummaryPrompt('')));

        return match ($model) {
            'gpt-4' => 16000,
            default => 4000
        } - $summaryLength;
    }

    private static function getSummaryPrompt(string $encodedHistory)
    {
        return [
            [
                'role' => 'system',
                'content' => 'Summarize this as short and concisely as possible without introduction:' . "\n" . $encodedHistory,
            ],
        ];
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
            return $this->fakeAnswerString('De chat is momenteel uitgeschakeld. Neem contact op met je docent.');
        }

        $model = self::getModelId($request->input('model'));

        if (!user()->canChatWithModel($model)) {
            return $this->fakeAnswerString('Je hebt niet genoeg chat tokens om '. $model .' te gebruiken. Probeer het later opnieuw.');
        }

        $history = $request->input('history');
        $shouldSummarizeHistory = $request->input('should_summarize_history');

        if ($shouldSummarizeHistory) {
            // JSON encode the history and ask the AI to summarize it
            $encodedHistory = json_encode($history);

            $provider = new EncoderProvider();
            $encoder = $provider->getForModel($model);

            // Nasty hack to get under the token limit. We will lose context, but it's better than nothing
            do {
                $tokens = count($encoder->encode($encodedHistory));

                if ($tokens > self::getModelTokenLimit($model) && count($history) > 2) {
                    $history = array_slice($history, 1);
                    $encodedHistory = json_encode($history);
                }
            } while ($tokens > self::getModelTokenLimit($model) && count($history) > 2);

            // If it's still too long, we will just summarize the last message, but trim it to the token limit
            if ($tokens > self::getModelTokenLimit($model)) {
                $encodedHistoryTokens = $encoder->encode($encodedHistory);

                if (count($encodedHistoryTokens) > self::getModelTokenLimit($model)) {
                    $encodedHistory = $encoder->decode(array_slice($encodedHistoryTokens, 0, self::getModelTokenLimit($model)));
                }
            }

            $history = self::getSummaryPrompt($encodedHistory);
        } else {
            $promptMessage = $history[count($history) - 1];

            if ($promptMessage['content'] === '') {
                return $this->fakeAnswerString('Sorry, ik heb je bericht niet begrepen. Probeer het opnieuw.');
            }
        }

        user()->registerChatWithModel($model);

        // Flush whatever is in the output buffer so we can immediately send fully formed JSON responses
        @ob_end_flush();

        return new StreamedResponse(function () use ($request, $model, $history, $shouldSummarizeHistory) {
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
                                        'is_summary' => $shouldSummarizeHistory,
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
                        'content' => 'Sorry, ik kon geen antwoord van de AI krijgen. Het lijkt erop dat de tokenlimiet is bereikt. Vernieuw de pagina om een nieuwe chat te starten of gebruik de knop om door te gaan met een samenvatting van het bovenstaande.',
                        'can_be_summarized' => true,
                        'error' => $e->getMessage(),
                    ]) . "\n\n";
                } else {
                    echo json_encode([
                        'content' => 'Sorry, ik kon geen antwoord van de AI krijgen. Probeer het later opnieuw of vernieuw de pagina om een nieuwe chat te starten.',
                        'error' => $e->getMessage(),
                    ]) . "\n\n";
                }
            }
        });
    }
}
