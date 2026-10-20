<?php

declare(strict_types=1);

namespace App\Services\AskAi\Query;

use App\Models\Customer;
use App\Services\AskAi\AskAiQueryExecutor;
use App\Services\AskAi\AskAiSearchPlanValidator;
use App\Services\AskAi\Query\Contracts\AskAiPublicBrainInterface;

/**
 * Public Ask AI query orchestration for POST /api/ask-ai/query.
 */
final class AskAiPublicQueryService
{
    public const OUT_OF_SCOPE_MESSAGE = 'I focus on HomeTrees — properties, projects, rent agreements, furniture, home services, and related home help. Ask me something about finding a home or a HomeTrees service!';

    public function __construct(
        protected AskAiPublicBrainInterface $brain,
        protected AskAiThreadStore $threads,
        protected AskAiDailyQuotaService $quota,
        protected AskAiSearchPlanValidator $planValidator,
        protected AskAiQueryExecutor $executor,
        protected AskAiPublicCardTransformer $cards,
        protected AskAiProjectQuery $projects,
        protected AskAiGuidanceCatalog $guidanceCatalog,
        protected AskAiConversationLocationStore $conversationLocations,
        protected AskAiAreaResolver $areaResolver,
    ) {}

    /**
     * @param  array{
     *     message: string,
     *     conversation_id: ?string,
     *     city: ?string,
     *     guest_id: ?string,
     *     ip: ?string,
     *     history: list<array{role: string, content: string}>
     * }  $input
     * @return array{http: int, body: array<string, mixed>}
     */
    public function handle(array $input, ?Customer $customer): array
    {
        if (! config('ask_ai.public_query.enabled', true)) {
            return [
                'http' => 503,
                'body' => [
                    'error' => true,
                    'message' => 'Ask AI is currently unavailable.',
                    'data' => [
                        'type' => 'error',
                        'errorKind' => 'ai',
                        'message' => 'Ask AI is currently unavailable.',
                        'limit' => $this->quota->snapshot($customer, $input['guest_id'] ?? null, $input['ip'] ?? null),
                    ],
                ],
            ];
        }

        $snap = $this->quota->snapshot($customer, $input['guest_id'] ?? null, $input['ip'] ?? null);
        if ($snap['exhausted']) {
            return [
                'http' => 429,
                'body' => [
                    'error' => true,
                    'key' => 'quota_exhausted',
                    'message' => 'Daily Ask AI limit reached',
                    'code' => 429,
                    'data' => [
                        'type' => 'quota',
                        'limit' => [
                            'remaining' => 0,
                            'limit' => $snap['limit'],
                            'audience' => $snap['audience'],
                        ],
                    ],
                ],
            ];
        }

        $limitInfo = $this->quota->consume($customer, $input['guest_id'] ?? null, $input['ip'] ?? null);
        $conversationId = $input['conversation_id'] ?? null;
        $thread = $this->threads->get($conversationId);
        $history = $this->threads->mergeHistory($conversationId, $input['history'] ?? []);
        $sticky = $thread['location'] ?? $this->conversationLocations->get($conversationId);

        $decision = $this->brain->interpret((string) $input['message'], $history, [
            'city_hint' => $input['city'] ?? null,
            'sticky_location' => $sticky,
            'sticky_area' => $thread['area'] ?? null,
            'pending_route' => $thread['pending_route'] ?? null,
            'last_search' => $thread['last_search'],
            'assistant_replies' => $thread['assistant_replies'],
        ]);

        $route = [
            'purpose' => $decision['purpose'],
            'bhk' => $decision['bhk'],
            'location' => $decision['location'],
            'area' => $decision['area'] ?? null,
            'address_query' => $decision['address_query'] ?? null,
            'maxPrice' => $decision['maxPrice'],
        ];

        // Purpose-only follow-up after clarify: restore BHK / area / city / budget.
        if (
            in_array($decision['intent'], ['search_properties', 'search_projects', 'search_both'], true)
            && is_array($thread['pending_route'] ?? null)
        ) {
            $pending = $thread['pending_route'];
            foreach (['bhk', 'location', 'area', 'address_query', 'maxPrice'] as $key) {
                if (empty($route[$key]) && ! empty($pending[$key])) {
                    $route[$key] = $pending[$key];
                }
            }
        }

        if (! empty($route['location'])) {
            $this->conversationLocations->put($conversationId, (string) $route['location']);
        }

        $data = match ($decision['intent']) {
            'out_of_scope' => $this->messagePayload(
                $decision['reply'] !== '' ? $decision['reply'] : self::OUT_OF_SCOPE_MESSAGE,
                $limitInfo,
                $decision['suggestions']
            ),
            'chat' => $this->messagePayload(
                $decision['reply'] !== '' ? $decision['reply'] : 'How can I help with HomeTrees today?',
                $limitInfo,
                $decision['suggestions']
            ),
            'guidance' => $this->guidanceFromDecision($decision, $limitInfo),
            'clarify_purpose' => $this->clarificationPurpose($limitInfo, $route, $decision['reply']),
            'search_properties' => $this->searchProperties($route, $limitInfo, $decision['reply']),
            'search_projects' => $this->searchProjects($route, $limitInfo, $decision['reply']),
            'search_both' => $this->searchBoth($route, $limitInfo, $decision['reply']),
            default => $this->messagePayload(
                $decision['reply'] !== '' ? $decision['reply'] : self::OUT_OF_SCOPE_MESSAGE,
                $limitInfo,
                $decision['suggestions']
            ),
        };

        $assistantText = (string) ($data['message'] ?? '');
        $lastSearch = in_array($data['type'] ?? '', ['results', 'no_results'], true)
            ? [
                'filters' => $data['filters'] ?? null,
                'summary' => $data['summary'] ?? [],
                'properties' => array_map(
                    static fn (array $p): array => [
                        'id' => $p['id'] ?? null,
                        'title' => $p['title'] ?? null,
                        'price' => $p['price'] ?? null,
                        'location' => $p['location'] ?? null,
                    ],
                    array_slice((array) ($data['properties'] ?? []), 0, 5)
                ),
                'projects' => array_map(
                    static fn (array $p): array => [
                        'id' => $p['id'] ?? null,
                        'title' => $p['title'] ?? null,
                        'starting_price' => $p['starting_price'] ?? null,
                    ],
                    array_slice((array) ($data['projects'] ?? []), 0, 5)
                ),
            ]
            : null;

        $pendingRoute = ($data['type'] ?? '') === 'clarification' ? $route : null;

        $this->threads->append(
            $conversationId,
            $history,
            (string) $input['message'],
            $assistantText,
            $route['location'] ?? $sticky,
            $lastSearch,
            [
                'area' => $route['area'] ?? ($thread['area'] ?? null),
                'pending_route' => $pendingRoute,
            ]
        );

        return [
            'http' => 200,
            'body' => [
                'error' => false,
                'message' => 'ok',
                'data' => $data,
            ],
        ];
    }

    /**
     * @param  array{remaining: int, limit: int, audience: string}  $limitInfo
     * @param  list<string>  $suggestions
     * @return array<string, mixed>
     */
    private function messagePayload(string $message, array $limitInfo, array $suggestions = []): array
    {
        return [
            'type' => 'message',
            'message' => $message,
            'filters' => null,
            'chips' => [],
            'summary' => [],
            'properties' => [],
            'projects' => [],
            'count' => 0,
            'suggestions' => $suggestions !== [] ? $suggestions : ['2 BHK for rent in Vadodara', 'Show residential projects', 'Rent agreement'],
            'clarification' => null,
            'alternatives' => [],
            'limit' => $limitInfo,
            'ctaLabel' => '',
            'ctaHref' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array{remaining: int, limit: int, audience: string}  $limitInfo
     * @return array<string, mixed>
     */
    private function guidanceFromDecision(array $decision, array $limitInfo): array
    {
        $fromCatalog = null;
        foreach ($this->guidanceCatalog->all() as $entry) {
            if ($entry['id'] === ($decision['guidance_id'] ?? null)) {
                $fromCatalog = $entry;
                break;
            }
        }

        $message = $decision['reply'] !== ''
            ? $decision['reply']
            : ($fromCatalog['message'] ?? 'HomeTrees can help with properties, projects, and home services.');
        $ctaLabel = $fromCatalog['ctaLabel'] ?? 'Contact us';
        $ctaHref = $fromCatalog['ctaHref'] ?? '/contact-us/';
        $suggestions = $decision['suggestions'] !== []
            ? $decision['suggestions']
            : ($fromCatalog['suggestions'] ?? ['2 BHK for rent', 'Projects in Ahmedabad']);

        return [
            'type' => 'guidance',
            'message' => $message,
            'filters' => null,
            'chips' => [],
            'summary' => [],
            'properties' => [],
            'projects' => [],
            'count' => 0,
            'suggestions' => $suggestions,
            'clarification' => null,
            'alternatives' => [],
            'limit' => $limitInfo,
            'ctaLabel' => $ctaLabel,
            'ctaHref' => $ctaHref,
        ];
    }

    /**
     * @param  array{remaining: int, limit: int, audience: string}  $limitInfo
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    private function clarificationPurpose(array $limitInfo, array $route = [], string $reply = ''): array
    {
        $filters = $this->filtersFromRoute($route);
        $chips = $this->chipsFromFilters($filters);

        return [
            'type' => 'clarification',
            'message' => $reply !== '' ? $reply : 'Are you looking to buy or rent?',
            'filters' => $filters !== [] ? $filters : null,
            'chips' => $chips,
            'summary' => array_values(array_map(static fn (array $chip): string => $chip['label'], $chips)),
            'properties' => [],
            'projects' => [],
            'count' => 0,
            'suggestions' => [],
            'clarification' => [
                'question' => 'Are you looking to buy or rent?',
                'options' => [
                    ['id' => 'buy', 'label' => 'Buy', 'patch' => ['purpose' => 'sale']],
                    ['id' => 'rent', 'label' => 'Rent', 'patch' => ['purpose' => 'rent']],
                ],
            ],
            'alternatives' => [],
            'limit' => $limitInfo,
            'ctaLabel' => '',
            'ctaHref' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array{remaining: int, limit: int, audience: string}  $limitInfo
     * @return array<string, mixed>
     */
    private function searchProperties(array $route, array $limitInfo, string $llmReply = ''): array
    {
        $limit = max(1, (int) config('ask_ai.public_query.result_limit', 8));
        $plan = $this->buildPropertyPlan($route);
        $validated = $this->planValidator->validate($plan);
        $executed = $this->executor->execute($validated['plan'], $limit, 0);
        $properties = $this->cards->properties($executed['items']);
        $widened = false;

        // Area / address filter empty but city+BHK has inventory → widen.
        if ($properties === [] && (! empty($route['area']) || ! empty($route['address_query']))) {
            $wideRoute = $route;
            unset($wideRoute['area'], $wideRoute['address_query']);
            $widePlan = $this->buildPropertyPlan($wideRoute);
            $wideValidated = $this->planValidator->validate($widePlan);
            $wideExecuted = $this->executor->execute($wideValidated['plan'], $limit, 0);
            $wideProperties = $this->cards->properties($wideExecuted['items']);
            if ($wideProperties !== []) {
                $properties = $wideProperties;
                $widened = true;
            }
        }

        $placeLabel = (string) ($route['area'] ?? $route['address_query'] ?? '');
        if ($llmReply !== '') {
            $message = $llmReply;
        } elseif ($widened && $placeLabel !== '') {
            $message = 'I could not find exact matches in ' . $placeLabel
                . ', so here are ' . count($properties) . ' nearby option'
                . (count($properties) === 1 ? '' : 's')
                . ' in ' . ((string) ($route['location'] ?? 'your city')) . '.';
        } else {
            $message = $this->propertyMessage($route, count($properties));
        }

        return $this->resultsPayload($route, $properties, [], $limitInfo, $message);
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array{remaining: int, limit: int, audience: string}  $limitInfo
     * @return array<string, mixed>
     */
    private function searchProjects(array $route, array $limitInfo, string $llmReply = ''): array
    {
        $projects = $this->cards->projects($this->projects->search($route));
        $message = $llmReply !== '' ? $llmReply : $this->projectMessage($route, count($projects));

        return $this->resultsPayload($route, [], $projects, $limitInfo, $message);
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array{remaining: int, limit: int, audience: string}  $limitInfo
     * @return array<string, mixed>
     */
    private function searchBoth(array $route, array $limitInfo, string $llmReply = ''): array
    {
        $limit = max(1, (int) config('ask_ai.public_query.result_limit', 8));
        $plan = $this->buildPropertyPlan($route);
        $validated = $this->planValidator->validate($plan);
        $executed = $this->executor->execute($validated['plan'], $limit, 0);
        $properties = $this->cards->properties($executed['items']);
        $projects = $this->cards->projects($this->projects->search($route));

        $message = $llmReply;
        if ($message === '') {
            $message = 'Here is what I found across properties and projects.';
            if ($properties === [] && $projects === []) {
                $message = 'I could not find matching properties or projects. Try another city, BHK, or budget.';
            }
        }

        return $this->resultsPayload($route, $properties, $projects, $limitInfo, $message);
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    private function buildPropertyPlan(array $route): array
    {
        $purpose = ($route['purpose'] ?? 'rent') === 'sale' ? 0 : 1;
        $plan = [
            'intent' => 'search',
            'mode' => 'structured_search',
            'property_type' => $purpose,
            'hard' => ['property_type'],
        ];

        if (! empty($route['bhk'])) {
            $plan['bhk'] = [(int) $route['bhk'] . 'BHK'];
            $plan['hard'][] = 'bhk';
        }

        // City only — never "Area, City" in location.city.
        if (! empty($route['location'])) {
            $plan['location'] = [
                'city' => (string) $route['location'],
                'state' => null,
                'country' => null,
                'place_id' => null,
                'latitude' => null,
                'longitude' => null,
                'range' => null,
            ];
            $plan['hard'][] = 'location.city';
        }

        // Same areas[] for rent and buy (property_type already set above).
        $areaNames = $this->areaResolver->expandForSearch(
            isset($route['area']) ? (string) $route['area'] : null,
            isset($route['location']) ? (string) $route['location'] : null
        );
        if ($areaNames !== []) {
            $plan['areas'] = $areaNames;
            $plan['hard'][] = 'areas';
        } elseif (! empty($route['address_query'])) {
            $plan['address_contains'] = (string) $route['address_query'];
            $plan['hard'][] = 'address_contains';
        }

        if (! empty($route['maxPrice'])) {
            $plan['price'] = ['min' => null, 'max' => (int) $route['maxPrice']];
            $plan['hard'][] = 'price';
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  list<array<string, mixed>>  $properties
     * @param  list<array<string, mixed>>  $projects
     * @param  array{remaining: int, limit: int, audience: string}  $limitInfo
     * @return array<string, mixed>
     */
    private function resultsPayload(array $route, array $properties, array $projects, array $limitInfo, string $message): array
    {
        $count = count($properties) + count($projects);
        $filters = $this->filtersFromRoute($route);
        $chips = $this->chipsFromFilters($filters);
        $summary = array_values(array_map(static fn (array $chip): string => $chip['label'], $chips));

        return [
            'type' => $count > 0 ? 'results' : 'no_results',
            'message' => $message,
            'filters' => $filters,
            'chips' => $chips,
            'summary' => $summary,
            'properties' => $properties,
            'projects' => $projects,
            'count' => $count,
            'suggestions' => $this->suggestionsFor($route, $count > 0 ? 'results' : 'no_results'),
            'clarification' => null,
            'alternatives' => [],
            'limit' => $limitInfo,
            'ctaLabel' => '',
            'ctaHref' => '',
        ];
    }

    /**
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    private function filtersFromRoute(array $route): array
    {
        $filters = [];
        if (! empty($route['purpose'])) {
            $filters['purpose'] = $route['purpose'] === 'sale' ? 'sale' : 'rent';
        }
        if (! empty($route['bhk'])) {
            $filters['bhk'] = (int) $route['bhk'];
        }
        // filters.location is always the city searched (never "Bopal, Ahmedabad").
        if (! empty($route['location'])) {
            $filters['location'] = (string) $route['location'];
        }
        if (! empty($route['area'])) {
            $filters['area'] = (string) $route['area'];
        } elseif (! empty($route['address_query'])) {
            $filters['area'] = (string) $route['address_query'];
        }
        if (! empty($route['maxPrice'])) {
            $filters['maxPrice'] = (int) $route['maxPrice'];
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{id: string, label: string}>
     */
    private function chipsFromFilters(array $filters): array
    {
        $chips = [];
        if (isset($filters['purpose'])) {
            $chips[] = [
                'id' => 'purpose',
                'label' => $filters['purpose'] === 'sale' ? 'Buy' : 'Rent',
            ];
        }
        if (isset($filters['bhk'])) {
            $chips[] = ['id' => 'bhk', 'label' => $filters['bhk'] . ' BHK'];
        }
        if (isset($filters['area'])) {
            $chips[] = ['id' => 'area', 'label' => (string) $filters['area']];
        }
        if (isset($filters['location'])) {
            $chips[] = ['id' => 'location', 'label' => (string) $filters['location']];
        }
        if (isset($filters['maxPrice'])) {
            $chips[] = [
                'id' => 'maxPrice',
                'label' => 'Under ₹' . number_format((int) $filters['maxPrice']),
            ];
        }

        return $chips;
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function propertyMessage(array $route, int $count): string
    {
        if ($count === 0) {
            return 'I could not find matching properties. Try adjusting city, BHK, or budget.';
        }

        $bits = [];
        if (! empty($route['bhk'])) {
            $bits[] = (int) $route['bhk'] . ' BHK';
        }
        if (($route['purpose'] ?? '') === 'rent') {
            $bits[] = 'rent';
        } elseif (($route['purpose'] ?? '') === 'sale') {
            $bits[] = 'sale';
        }
        $place = $route['area'] ?? $route['address_query'] ?? null;
        if (! empty($place) && ! empty($route['location'])) {
            $bits[] = 'in ' . $place . ', ' . $route['location'];
        } elseif (! empty($place)) {
            $bits[] = 'in ' . $place;
        } elseif (! empty($route['location'])) {
            $bits[] = 'in ' . $route['location'];
        }
        if (! empty($route['maxPrice'])) {
            $bits[] = 'under ₹' . number_format((int) $route['maxPrice']);
        }

        $desc = $bits !== [] ? ' for ' . implode(' ', $bits) : '';

        return 'I found ' . $count . ' match' . ($count === 1 ? '' : 'es') . $desc . '.';
    }

    /**
     * @param  array<string, mixed>  $route
     */
    private function projectMessage(array $route, int $count): string
    {
        if ($count === 0) {
            return 'I could not find matching projects. Try another city or configuration.';
        }

        $where = ! empty($route['location']) ? ' in ' . $route['location'] : '';

        return 'Here are residential projects' . $where . ' that match.';
    }

    /**
     * @param  array<string, mixed>  $route
     * @return list<string>
     */
    private function suggestionsFor(array $route, string $type): array
    {
        if ($type === 'no_results') {
            return ['Widen budget', 'Try another city', 'Show projects'];
        }

        $suggestions = ['Show furnished', 'Increase budget'];
        if (! empty($route['location'])) {
            $suggestions[] = 'Only ' . $route['location'];
        } else {
            $suggestions[] = 'In Vadodara';
        }

        return $suggestions;
    }
}
