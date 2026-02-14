<?php

namespace App\Http\Controllers;

use App\Settings\ChatSettings;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApiController extends Controller
{
    public static function getModelIds()
    {
        $settings = app(ChatSettings::class);

        return [
            'mini' => $settings->model_mini,
            'advanced' => $settings->model_advanced,
        ];
    }


    public static function getModelId(string $modelId)
    {
        $models = self::getModelIds();

        return isset($models[$modelId]) ? $models[$modelId] : $models['mini'];
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

        $modelId = $request->input('model');

        if (!user()->canChatWithModel($modelId)) {
            return $this->fakeAnswerString('Je hebt niet genoeg chat tokens over om ' . $modelId . ' vandaag nog te gebruiken. Gebruik het andere model, of vraag een teamgenoot om de vraag voor je te stellen.');
        }

        $history = $request->input('history');

        // Inject the system prompt before all messages
        array_unshift($history, [
            'role' => 'system',
            'content' => "Je bent een behulpzame assistent genaamd 'CurioGPT' die helpt met vragen over Software Development.",
        ]);

        $promptMessage = $history[count($history) - 1];

        if ($promptMessage['content'] === '') {
            return $this->fakeAnswerString('Sorry, ik heb je bericht niet begrepen. Probeer het opnieuw.');
        }

        // Flush whatever is in the output buffer so we can immediately send fully formed JSON responses
        @ob_end_flush();

        return new StreamedResponse(function () use ($request, $modelId, $history, $settings) {
            $model = self::getModelId($modelId);
            $apiKey = config('app.openai_api_key');
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

                $promptMessage = $history[count($history) - 1];
                $fullResponse = '';
                $partialChunk = '';
                $tokenCount = 0;

                // Stream the response through and process chunks
                while (!$body->eof()) {
                    $chunk = $body->read(1024);
                    $text = $partialChunk . $chunk;
                    $chunks = explode("\n", $text);

                    for ($i = 0; $i < count($chunks) - 1; $i++) {
                        if (trim($chunks[$i]) !== '') {
                            $tokenCount++;

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

                                    $fullResponse .= $content;

                                    // Force the response to be sent to the client (to avoid buffering)
                                    @flush();
                                }
                            }
                        }
                    }

                    // Prepare the partial chunk for the next iteration
                    $partialChunk = $chunks[count($chunks) - 1];
                }

                user()->registerChatWithModel($modelId, $tokenCount, $promptMessage['content'], $fullResponse);
            } catch (RequestException $e) {
                $statusCode = $e->getResponse()->getStatusCode();

                if ($statusCode === 400) {
                    echo json_encode([
                        'content' => 'Sorry, ik kon geen antwoord van de AI krijgen. Het lijkt erop dat de tokenlimiet is bereikt. Vernieuw de pagina om een nieuwe chat te starten.',
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
