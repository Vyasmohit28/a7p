<?php

declare(strict_types=1);

namespace App\Services\AskAi\Query;

use App\Services\AskAi\Query\Contracts\AskAiPublicBrainInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public Ask AI brain via OpenAI Responses API (server-side key only).
 */
final class LunaAskAiPublicBrain implements AskAiPublicBrainInterface
{
    public function __construct(
        protected HeuristicAskAiPublicBrain $fallback,
        protected AskAiCityResolver $cities,
        protected AskAiAreaResolver $areas,
    ) {}

    public function interpret(string $message, array $history, array $context): array
    {
        $apiKey = config('ask_ai.provider.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return $this->fallback->interpret($message, $history, $context);
        }

        try {
            $decision = $this->callLuna($message, $history, $context, trim($apiKey));
            if ($decision !== null) {
                return $this->normalizeDecision($decision, $message, $context);
            }
        } catch (Throwable $e) {
            Log::warning('Ask AI public Luna brain failed; using heuristic fallback', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $this->fallback->interpret($message, $history, $context);
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function callLuna(string $message, array $history, array $context, string $apiKey): ?array
    {
        $model = (string) (config('ask_ai.provider.model') ?: 'gpt-5.6-luna');
        $timeout = max(1, (int) config('ask_ai.provider.timeout_seconds', 30));
        $baseUrl = rtrim((string) config('ask_ai.provider.base_url', 'https://api.openai.com/v1'), '/');
        $maxTokens = max(128, (int) config('ask_ai.reply.max_output_tokens', 800));

        $input = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => json_encode([
                'task' => 'interpret_hometrees_turn',
                'message' => $message,
                'history' => $history,
                'city_hint' => $context['city_hint'] ?? null,
                'sticky_location' => $context['sticky_location'] ?? null,
                'last_search' => $context['last_search'] ?? null,
                'assistant_replies' => (int) ($context['assistant_replies'] ?? 0),
            ], JSON_UNESCAPED_UNICODE)],
        ];

        $payload = [
            'model' => $model,
            'input' => $input,
            'max_output_tokens' => $maxTokens,
            'store' => false,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'hometrees_ask_ai_turn',
                    'strict' => true,
                    'schema' => $this->jsonSchema(),
                ],
            ],
        ];

        $effort = config('ask_ai.provider.reasoning_effort');
        if (is_string($effort) && $effort !== '') {
            $payload['reasoning'] = ['effort' => $effort];
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->post($baseUrl . '/responses', $payload);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning('Ask AI public Luna HTTP error', ['status' => $response->status()]);

            return null;
        }

        $text = $this->extractOutputText($response->json());
        if ($text === null || $text === '') {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $context
     * @return array{
     *     intent: string,
     *     reply: string,
     *     purpose: ?string,
     *     bhk: ?int,
     *     location: ?string,
 *     area: ?string,
 *     address_query: ?string,
 *     maxPrice: ?int,
     *     guidance_id: ?string,
     *     suggestions: list<string>
     * }
     */
    private function normalizeDecision(array $raw, string $message, array $context): array
    {
        $allowed = [
            'search_properties', 'search_projects', 'search_both',
            'chat', 'guidance', 'out_of_scope', 'clarify_purpose',
        ];
        $intent = (string) ($raw['intent'] ?? 'chat');
        if (! in_array($intent, $allowed, true)) {
            $intent = 'chat';
        }

        $purpose = $raw['purpose'] ?? null;
        $purpose = in_array($purpose, ['rent', 'sale'], true) ? $purpose : null;
        $maxPrice = isset($raw['maxPrice']) && is_numeric($raw['maxPrice']) ? (int) $raw['maxPrice'] : null;

        // Server-side purpose defaults for listing search (never invent for chat).
        if ($purpose === null && $maxPrice !== null && str_starts_with($intent, 'search_')) {
            if ($maxPrice <= 100000) {
                $purpose = 'rent';
            } elseif ($maxPrice >= 300000) {
                $purpose = 'sale';
            }
        }
        if ($intent === 'clarify_purpose' && $maxPrice !== null) {
            if ($maxPrice <= 100000) {
                $intent = 'search_properties';
                $purpose = 'rent';
            } elseif ($maxPrice >= 300000) {
                $intent = 'search_properties';
                $purpose = 'sale';
            }
        }

        // Listing search without purpose and without budget default → ask Buy/Rent.
        if ($purpose === null && str_starts_with($intent, 'search_')) {
            $intent = 'clarify_purpose';
        }

        // City only — never "Bopal, Ahmedabad" as location.city.
        $rawLocation = is_string($raw['location'] ?? null) ? (string) $raw['location'] : null;
        $location = $this->cities->resolve(
            $message,
            $rawLocation ?? ($context['city_hint'] ?? null),
            $context['sticky_location'] ?? null
        );

        $area = $this->areas->fromMessage($message);
        if ($area === null && is_string($raw['area'] ?? null) && trim((string) $raw['area']) !== '') {
            $area = $this->areas->normalize((string) $raw['area']);
        }
        // If Luna stuffed "Bopal, Ahmedabad" into location, recover the locality.
        if ($area === null && $rawLocation !== null && AskAiAreaResolver::isCatalogArea($rawLocation)) {
            $area = $this->areas->normalize($rawLocation);
        }
        if ($area === null && is_string($context['sticky_area'] ?? null)) {
            $stickyArea = $this->areas->normalize((string) $context['sticky_area']);
            if ($stickyArea !== null && AskAiAreaResolver::isCatalogArea($stickyArea)) {
                $area = $stickyArea;
            }
        }

        $addressQuery = null;
        if ($area === null) {
            $addressQuery = $this->areas->freeTextFromMessage($message, $location);
        }

        $bhk = isset($raw['bhk']) && is_numeric($raw['bhk']) ? (int) $raw['bhk'] : null;

        // Buy/Rent chip after clarify — resume pending listing filters.
        $pending = is_array($context['pending_route'] ?? null) ? $context['pending_route'] : null;
        $lowerMsg = mb_strtolower(trim($message));
        if (
            $pending !== null
            && $purpose !== null
            && preg_match('/^(rent|for rent|buy|for sale|sale|purchase)$/u', $lowerMsg) === 1
        ) {
            $intent = 'search_properties';
            if ($bhk === null && ! empty($pending['bhk'])) {
                $bhk = (int) $pending['bhk'];
            }
            if ($location === null && ! empty($pending['location'])) {
                $location = (string) $pending['location'];
            }
            if ($area === null && ! empty($pending['area'])) {
                $area = (string) $pending['area'];
            }
            if ($addressQuery === null && ! empty($pending['address_query'])) {
                $addressQuery = (string) $pending['address_query'];
            }
            if ($maxPrice === null && ! empty($pending['maxPrice'])) {
                $maxPrice = (int) $pending['maxPrice'];
            }
        }

        $suggestions = [];
        foreach ((array) ($raw['suggestions'] ?? []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $suggestions[] = trim($item);
            }
        }

        return [
            'intent' => $intent,
            'reply' => trim((string) ($raw['reply'] ?? '')),
            'purpose' => $purpose,
            'bhk' => $bhk,
            'location' => $location,
            'area' => $area,
            'address_query' => $addressQuery,
            'maxPrice' => $maxPrice,
            'guidance_id' => is_string($raw['guidance_id'] ?? null) ? (string) $raw['guidance_id'] : null,
            'suggestions' => array_slice($suggestions, 0, 5),
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are the HomeTrees Ask AI assistant for Gujarat real-estate and home services.
Answer like a helpful ChatGPT for HomeTrees only: properties, projects, rent agreements, interiors (/furniture/), furniture store (/rent-furniture/), home services, smart pricing, society partner, contact, EMI.
Never invent listing prices or competitor claims. Never expose secrets or API keys.
Do NOT force Buy vs Rent for opinions, small talk, or follow-ups about earlier listings.
Do NOT greet with Good morning/afternoon/evening unless assistant_replies is 0 AND intent is chat — and then at most one short welcome line inside reply.

Return JSON matching the schema.
intent values:
- search_properties / search_projects / search_both — user wants listings
- chat — opinions, follow-ups ("is the first one good?"), clarifications without a new search
- guidance — HomeTrees service help (set guidance_id)
- clarify_purpose — listing search when buy vs rent is unknown (no explicit purpose and no budget default)
- out_of_scope — generic AI/tech/unrelated

Location vs area (critical):
- location = CITY only (Ahmedabad, Vadodara, Surat, Rajkot, Gandhinagar, …). Never "Bopal, Ahmedabad".
- area = neighbourhood / locality only (Bopal, South Bopal, Alkapuri, SG Highway, Gotri, Satellite, …).
- When the user names both, set both fields separately.

Purpose rules for search only:
- explicit rent/lease → rent; buy/sale → sale
- maxPrice <= 100000 → rent (do not ask)
- maxPrice >= 300000 (e.g. 50–60 lakh) → sale (do not ask)
- otherwise without purpose → clarify_purpose (do not search with empty results)

For chat follow-ups, put a natural conversational reply that references last_search titles when present.
guidance_id one of: rent_agreement, interior, furniture_store, home_services, smart_pricing, society_partner, contact, emi.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => [
                        'search_properties', 'search_projects', 'search_both',
                        'chat', 'guidance', 'out_of_scope', 'clarify_purpose',
                    ],
                ],
                'reply' => ['type' => 'string'],
                'purpose' => ['type' => ['string', 'null'], 'enum' => ['rent', 'sale', null]],
                'bhk' => ['type' => ['integer', 'null']],
                'location' => ['type' => ['string', 'null']],
                'area' => ['type' => ['string', 'null']],
                'maxPrice' => ['type' => ['integer', 'null']],
                'guidance_id' => ['type' => ['string', 'null']],
                'suggestions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [
                'intent', 'reply', 'purpose', 'bhk', 'location', 'area', 'maxPrice', 'guidance_id', 'suggestions',
            ],
        ];
    }

    /**
     * @param  mixed  $json
     */
    private function extractOutputText(mixed $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        if (isset($json['output_text']) && is_string($json['output_text'])) {
            return trim($json['output_text']);
        }

        $chunks = [];
        foreach ((array) ($json['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (! is_array($content)) {
                    continue;
                }
                if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) {
                    $chunks[] = $content['text'];
                }
            }
        }

        $text = trim(implode("\n", $chunks));

        return $text !== '' ? $text : null;
    }
}
