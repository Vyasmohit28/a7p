<?php

declare(strict_types=1);

namespace App\Services\AskAi\Query\Contracts;

/**
 * Interprets the latest user message with thread context for the public Ask AI API.
 *
 * @phpstan-type BrainDecision array{
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
interface AskAiPublicBrainInterface
{
    /**
     * @param  list<array{role: string, content: string}>  $history
     * @param  array{
     *     city_hint: ?string,
     *     sticky_location: ?string,
     *     last_search: ?array<string, mixed>,
     *     assistant_replies: int
     * }  $context
     * @return BrainDecision
     */
    public function interpret(string $message, array $history, array $context): array;
}
