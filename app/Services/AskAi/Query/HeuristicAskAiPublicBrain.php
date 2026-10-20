<?php

declare(strict_types=1);

namespace App\Services\AskAi\Query;

use App\Services\AskAi\Query\Contracts\AskAiPublicBrainInterface;

/**
 * Offline / fallback brain for public Ask AI when Luna is not configured.
 * Opinion and follow-up chat must not force Buy/Rent.
 */
final class HeuristicAskAiPublicBrain implements AskAiPublicBrainInterface
{
    public function __construct(
        protected AskAiCityResolver $cities,
        protected AskAiAreaResolver $areas,
        protected AskAiGuidanceCatalog $guidance,
    ) {}

    public function interpret(string $message, array $history, array $context): array
    {
        $trimmed = trim($message);
        $lower = mb_strtolower($trimmed);
        $location = $this->cities->resolve(
            $trimmed,
            $context['city_hint'] ?? null,
            $context['sticky_location'] ?? null
        );
        $areaFromMessage = $this->areas->fromMessage($trimmed);
        $area = $areaFromMessage;
        if ($area === null && is_string($context['sticky_area'] ?? null)) {
            $stickyArea = $this->areas->normalize((string) $context['sticky_area']);
            if ($stickyArea !== null && AskAiAreaResolver::isCatalogArea($stickyArea)) {
                $area = $stickyArea;
            }
        }
        $addressQuery = $areaFromMessage === null
            ? $this->areas->freeTextFromMessage($trimmed, $location)
            : null;
        $bhk = $this->detectBhk($lower);
        $maxPrice = $this->detectMaxPrice($lower);
        $purpose = $this->detectPurpose($lower);
        $purpose = $this->inferPurpose($purpose, $maxPrice);

        // Buy / Rent chip reply after clarify_purpose — resume pending listing search.
        $pending = is_array($context['pending_route'] ?? null) ? $context['pending_route'] : null;
        if ($pending !== null && $this->isPurposeOnlyReply($lower) && $purpose !== null) {
            return [
                'intent' => 'search_properties',
                'reply' => '',
                'purpose' => $purpose,
                'bhk' => $bhk ?? (isset($pending['bhk']) ? (int) $pending['bhk'] : null),
                'location' => $location ?? (isset($pending['location']) ? (string) $pending['location'] : null),
                'area' => $area ?? (isset($pending['area']) ? (string) $pending['area'] : null),
                'address_query' => $addressQuery
                    ?? (isset($pending['address_query']) ? (string) $pending['address_query'] : null),
                'maxPrice' => $maxPrice ?? (isset($pending['maxPrice']) ? (int) $pending['maxPrice'] : null),
                'guidance_id' => null,
                'suggestions' => [],
            ];
        }

        $base = [
            'intent' => 'out_of_scope',
            'reply' => '',
            'purpose' => $purpose,
            'bhk' => $bhk,
            'location' => $location,
            'area' => $area,
            'address_query' => $addressQuery,
            'maxPrice' => $maxPrice,
            'guidance_id' => null,
            'suggestions' => [],
        ];

        if ($this->isOutOfScope($lower)) {
            $base['intent'] = 'out_of_scope';
            $base['reply'] = AskAiPublicQueryService::OUT_OF_SCOPE_MESSAGE;

            return $base;
        }

        $lastSearch = is_array($context['last_search'] ?? null) ? $context['last_search'] : null;
        $hasPriorListings = is_array($lastSearch)
            && (
                ! empty($lastSearch['properties'])
                || ! empty($lastSearch['projects'])
                || ! empty($lastSearch['summary'])
            );

        if ($this->isOpinionOrChat($lower) && ! $this->isStrongNewSearch($lower, $bhk, $maxPrice)) {
            $base['intent'] = 'chat';
            $base['reply'] = $this->opinionReply($trimmed, $hasPriorListings, $lastSearch);
            $base['suggestions'] = ['Show cheaper options', '2 BHK for rent', 'Rent agreement'];

            return $base;
        }

        $guide = $this->guidance->match($trimmed);
        if ($guide !== null && ! $this->wantsListings($lower) && $bhk === null) {
            $base['intent'] = 'guidance';
            $base['guidance_id'] = $guide['id'];
            $base['reply'] = $guide['message'];
            $base['suggestions'] = $guide['suggestions'];

            return $base;
        }

        $wantsProjects = $this->wantsProjects($lower);
        $wantsProperties = $this->wantsProperties($lower) || $this->hasListingSignals($lower, $bhk, $maxPrice, $purpose);

        if ($wantsProjects && ! $wantsProperties) {
            $base['intent'] = 'search_projects';
            $base['reply'] = '';

            return $base;
        }

        if ($wantsProjects && $wantsProperties) {
            $base['intent'] = 'search_both';
            $base['reply'] = '';

            return $base;
        }

        if ($wantsProperties) {
            // No buy/rent and no budget heuristic → ask; do not empty-search or invent purpose.
            if ($purpose === null) {
                $base['intent'] = 'clarify_purpose';
                $base['reply'] = 'Are you looking to buy or rent?';

                return $base;
            }

            $base['intent'] = 'search_properties';
            $base['purpose'] = $purpose;
            $base['reply'] = '';

            return $base;
        }

        if ($guide !== null) {
            $base['intent'] = 'guidance';
            $base['guidance_id'] = $guide['id'];
            $base['reply'] = $guide['message'];
            $base['suggestions'] = $guide['suggestions'];

            return $base;
        }

        // Soft chat fallback for greetings / vague home questions.
        if ($this->isSoftChat($lower)) {
            $base['intent'] = 'chat';
            $base['reply'] = 'I can help you find homes on HomeTrees, compare listings, or guide you to rent agreements, interiors, furniture, and home services. What are you looking for?';
            $base['suggestions'] = ['2 BHK for rent in Vadodara', 'Projects in Ahmedabad', 'Rent agreement'];

            return $base;
        }

        $base['intent'] = 'out_of_scope';
        $base['reply'] = AskAiPublicQueryService::OUT_OF_SCOPE_MESSAGE;

        return $base;
    }

    /**
     * @param  array<string, mixed>|null  $lastSearch
     */
    private function opinionReply(string $message, bool $hasPriorListings, ?array $lastSearch): string
    {
        if ($hasPriorListings) {
            $props = array_values(array_filter(
                (array) ($lastSearch['properties'] ?? []),
                static fn ($row): bool => is_array($row)
            ));
            $index = 0;
            $lowerMsg = mb_strtolower($message);
            if (preg_match('/\bsecond\b/u', $lowerMsg) === 1) {
                $index = 1;
            } elseif (preg_match('/\bthird\b/u', $lowerMsg) === 1) {
                $index = 2;
            }
            $pick = $props[$index] ?? $props[0] ?? null;
            $title = is_array($pick) && ! empty($pick['title'])
                ? (string) $pick['title']
                : 'the first listing';
            $bits = [];
            if (is_array($pick) && ! empty($pick['location'])) {
                $bits[] = (string) $pick['location'];
            }
            if (is_array($pick) && isset($pick['price']) && is_numeric($pick['price'])) {
                $bits[] = '₹' . number_format((int) $pick['price']);
            }
            $detail = $bits !== [] ? ' (' . implode(', ', $bits) . ')' : '';
            $others = [];
            foreach (array_slice($props, 0, 3) as $i => $row) {
                if ($i === $index || ! is_array($row) || empty($row['title'])) {
                    continue;
                }
                $others[] = (string) $row['title'];
            }
            $compare = $others !== []
                ? ' Compared with ' . implode('; ', $others) . ', weigh location, budget, and furnishing for your family.'
                : ' Weigh location, budget, and furnishing for your family.';

            return "Looking at {$title}{$detail} from your earlier results: it can work well for a family if the layout, area, and rent/sale price fit your needs."
                . $compare
                . ' I can’t visit the home for you — check photos and verify details on the listing page before you decide.';
        }

        return 'I don’t have earlier listings in this chat yet. '
            . 'Search first (for example “3 BHK for rent in Bopal”), then ask which one is best for a family.';
    }

    private function isOpinionOrChat(string $lower): bool
    {
        return (bool) preg_match(
            '/\b(good or not|is (?:this|that|it|the(?:\s+(?:first|second|third))?(?:\s+(?:one|property|flat))?)\s+(?:good|best)|(?:which|what)(?:\s+one)?\s+is\s+best|best for (?:a )?family|worth (?:it|buying|renting)|should i|what do you think|your opinion|recommend|review|compare (?:them|these|those)|cheaper (?:ones|options)|tell me about (?:the |this |that )?(?:first|second|third|it|one))\b/u',
            $lower
        ) || (bool) preg_match('/\b(hello|hi|hey)\b.*\b(good|property|flat)\b/u', $lower);
    }

    private function isStrongNewSearch(string $lower, ?int $bhk, ?int $maxPrice): bool
    {
        return ($bhk !== null && ($maxPrice !== null || $this->detectPurpose($lower) !== null))
            || (bool) preg_match('/\b(show|find|search|list)\b.*\b(bhk|flat|property|project)\b/u', $lower);
    }

    private function isSoftChat(string $lower): bool
    {
        return (bool) preg_match('/^\s*(hello|hi|hey|thanks|thank you|ok|okay)\b/u', $lower)
            || (bool) preg_match('/\b(help me|what can you do)\b/u', $lower);
    }

    private function isOutOfScope(string $lower): bool
    {
        $needles = [
            'gpt-4', 'gpt4', 'chatgpt', 'openai', 'write python', 'write code',
            'capital of france', 'medical advice', 'crypto price',
        ];
        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return (bool) preg_match('/\bwhat is (gpt|chatgpt|machine learning|blockchain)\b/u', $lower);
    }

    private function wantsProjects(string $lower): bool
    {
        return (bool) preg_match('/\b(projects?|society|societies|builder project|township)\b/u', $lower);
    }

    private function wantsProperties(string $lower): bool
    {
        return (bool) preg_match('/\b(propert(?:y|ies)|flat|flats|apartment|apartments|house|villa|listing|listings|bhk|rk)\b/u', $lower);
    }

    private function wantsListings(string $lower): bool
    {
        return $this->wantsProjects($lower) || $this->wantsProperties($lower);
    }

    private function hasListingSignals(string $lower, ?int $bhk, ?int $maxPrice, ?string $purpose): bool
    {
        return $bhk !== null
            || $maxPrice !== null
            || $purpose !== null
            || (bool) preg_match('/\b(for rent|for sale|to rent|to buy|under ₹|under rs|within\s+\d)\b/u', $lower);
    }

    private function detectPurpose(string $lower): ?string
    {
        if (preg_match('/\b(rent|rental|lease|on rent|for rent)\b/u', $lower) === 1) {
            return 'rent';
        }
        if (preg_match('/\b(buy|sale|sell|purchase|for sale|to buy)\b/u', $lower) === 1) {
            return 'sale';
        }

        return null;
    }

    private function isPurposeOnlyReply(string $lower): bool
    {
        $trimmed = trim($lower);

        return (bool) preg_match('/^(rent|for rent|buy|for sale|sale|purchase)$/u', $trimmed);
    }

    private function inferPurpose(?string $purpose, ?int $maxPrice): ?string
    {
        if ($purpose !== null) {
            return $purpose;
        }
        if ($maxPrice === null) {
            return null;
        }
        if ($maxPrice <= 100000) {
            return 'rent';
        }
        if ($maxPrice >= 300000) {
            return 'sale';
        }

        return null;
    }

    private function detectBhk(string $lower): ?int
    {
        if (preg_match('/\b(\d(?:\.\d)?)\s*(?:bhk|rk)\b/u', $lower, $m) === 1) {
            $n = (float) $m[1];
            if ($n >= 1 && $n <= 10) {
                return (int) floor($n);
            }
        }

        return null;
    }

    private function detectMaxPrice(string $lower): ?int
    {
        if (preg_match('/(?:under|below|max(?:imum)?|upto|up to|within|budget(?:\s+of)?)\s*(?:₹|rs\.?|inr)?\s*([\d,]+(?:\.\d+)?)\s*(k|lakh|lac|lacs|l|cr)?/u', $lower, $m) === 1) {
            return $this->toRupees((float) str_replace(',', '', $m[1]), strtolower((string) ($m[2] ?? '')));
        }

        if (preg_match('/\b([\d,]+(?:\.\d+)?)\s*(lakh|lac|lacs|cr)\b/u', $lower, $m) === 1) {
            return $this->toRupees((float) str_replace(',', '', $m[1]), strtolower($m[2]));
        }

        return null;
    }

    private function toRupees(float $n, string $unit): int
    {
        if ($unit === 'k') {
            $n *= 1000;
        } elseif (in_array($unit, ['lakh', 'lac', 'lacs', 'l'], true)) {
            $n *= 100000;
        } elseif ($unit === 'cr') {
            $n *= 10000000;
        }

        return (int) round($n);
    }
}
