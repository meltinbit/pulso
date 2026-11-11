<?php

namespace App\Services;

use App\Exceptions\OpenAiApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Asks a question to an OpenAI model through the Responses API with the web
 * search tool, as a ChatGPT user with search would, and returns the answer,
 * its cited URLs and what it cost.
 */
class OpenAiResponsesClient
{
    /**
     * @return array{text: string, citations: list<array{url: string, title: string}>, input_tokens: int, output_tokens: int, searches: int, cost_usd: float}
     *
     * @throws OpenAiApiException
     */
    public function ask(string $apiKey, string $model, string $question): array
    {
        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(120)
                ->connectTimeout(5)
                ->post(rtrim(config('services.openai.base_url'), '/').'/responses', [
                    'model' => $model,
                    'input' => $question,
                    'tools' => [[
                        'type' => 'web_search',
                        'search_context_size' => 'low',
                        'user_location' => ['type' => 'approximate', 'country' => config('services.openai.country', 'IT')],
                    ]],
                    'reasoning' => ['effort' => config('services.openai.reasoning_effort', 'low')],
                    'store' => false,
                ]);
        } catch (ConnectionException) {
            throw new OpenAiApiException('OpenAI non risponde: riprova più tardi.');
        }

        if ($response->failed()) {
            throw $this->translateError($response);
        }

        $text = '';
        $citations = [];
        $searches = 0;

        foreach ($response->json('output', []) as $item) {
            if (($item['type'] ?? null) === 'web_search_call') {
                $searches++;

                continue;
            }

            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) !== 'output_text') {
                    continue;
                }

                $text .= ($text === '' ? '' : "\n").($content['text'] ?? '');

                foreach ($content['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? null) === 'url_citation' && filled($annotation['url'] ?? null)) {
                        $citations[] = ['url' => (string) $annotation['url'], 'title' => (string) ($annotation['title'] ?? '')];
                    }
                }
            }
        }

        $inputTokens = (int) $response->json('usage.input_tokens', 0);
        $outputTokens = (int) $response->json('usage.output_tokens', 0);

        return [
            'text' => $text,
            'citations' => $citations,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'searches' => $searches,
            'cost_usd' => $this->cost($model, $inputTokens, $outputTokens, $searches),
        ];
    }

    /**
     * Check a key with the free models endpoint.
     *
     * @throws OpenAiApiException
     */
    public function verifyKey(string $apiKey): void
    {
        try {
            $response = Http::withToken($apiKey)->acceptJson()->timeout(15)
                ->get(rtrim(config('services.openai.base_url'), '/').'/models');
        } catch (ConnectionException) {
            throw new OpenAiApiException('OpenAI non risponde: riprova più tardi.');
        }

        if ($response->failed()) {
            throw $this->translateError($response);
        }
    }

    /**
     * Cost in USD from the prices in config/services.php; unknown models use
     * a deliberately high fallback price.
     */
    public function cost(string $model, int $inputTokens, int $outputTokens, int $searches): float
    {
        $price = config('services.openai.prices')[$model] ?? config('services.openai.fallback_price');

        return round(
            $inputTokens / 1_000_000 * $price['input']
            + $outputTokens / 1_000_000 * $price['output']
            + $searches * (float) config('services.openai.web_search_price', 0.01),
            6,
        );
    }

    /**
     * Upper bound for one question, used to stop before crossing the cap.
     */
    public function estimatedCost(string $model): float
    {
        return $this->cost($model, 20_000, 2_000, 2);
    }

    private function translateError(Response $response): OpenAiApiException
    {
        $message = (string) $response->json('error.message', '');

        return match (true) {
            $response->status() === 401 => new OpenAiApiException('La API key OpenAI non è valida o è stata revocata: generane una nuova e salvala in Impostazioni → AI visibility.', isAuthError: true),
            $response->status() === 429 && str_contains((string) $response->json('error.code'), 'quota') => new OpenAiApiException('Credito OpenAI esaurito: ricarica il conto API su platform.openai.com.', isAuthError: true),
            $response->status() === 429 => new OpenAiApiException('Limite di richieste OpenAI raggiunto: riprova tra qualche minuto.'),
            $response->status() === 404 || str_contains($message, 'model') => new OpenAiApiException('Modello OpenAI non disponibile: controllalo in Impostazioni → AI visibility. Dettaglio: '.$message),
            default => new OpenAiApiException('Errore OpenAI'.($message !== '' ? ': '.$message : '.')),
        };
    }
}
