<?php

declare(strict_types=1);

namespace App\Services\AskAi\Query;

use Illuminate\Support\Facades\Cache;

/**
 * Conversation memory for POST /api/ask-ai/query (keyed by conversation_id).
 *
 * @phpstan-type Turn array{role: string, content: string}
 * @phpstan-type Thread array{
 *     turns: list<Turn>,
 *     location: ?string,
 *     area: ?string,
 *     pending_route: ?array<string, mixed>,
 *     last_search: ?array<string, mixed>,
 *     assistant_replies: int
 * }
 */
final class AskAiThreadStore
{
    /**
     * @return Thread
     */
    public function get(?string $conversationId): array
    {
        $id = $this->normalizeId($conversationId);
        if ($id === null) {
            return $this->empty();
        }

        $raw = Cache::get($this->key($id));
        if (! is_array($raw)) {
            return $this->empty();
        }

        return [
            'turns' => $this->sanitizeTurns($raw['turns'] ?? []),
            'location' => is_string($raw['location'] ?? null) ? trim((string) $raw['location']) : null,
            'area' => is_string($raw['area'] ?? null) ? trim((string) $raw['area']) : null,
            'pending_route' => is_array($raw['pending_route'] ?? null) ? $raw['pending_route'] : null,
            'last_search' => is_array($raw['last_search'] ?? null) ? $raw['last_search'] : null,
            'assistant_replies' => max(0, (int) ($raw['assistant_replies'] ?? 0)),
        ];
    }

    /**
     * @param  list<Turn>  $clientHistory
     * @return list<Turn>
     */
    public function mergeHistory(?string $conversationId, array $clientHistory): array
    {
        $stored = $this->get($conversationId)['turns'];
        $client = $this->sanitizeTurns($clientHistory);

        if ($stored === []) {
            return $client;
        }
        if ($client === []) {
            return $stored;
        }

        // Prefer longer thread; if both present, append client turns not already at the end of stored.
        $merged = $stored;
        foreach ($client as $turn) {
            $last = $merged !== [] ? $merged[array_key_last($merged)] : null;
            if ($last !== null
                && $last['role'] === $turn['role']
                && $last['content'] === $turn['content']) {
                continue;
            }
            $merged[] = $turn;
        }

        $max = max(2, (int) config('ask_ai.public_query.history_max_turns', 20));

        return array_slice($merged, -$max);
    }

    /**
     * @param  list<Turn>  $historyBefore
     * @param  array<string, mixed>|null  $lastSearch
     * @param  array{
     *     area?: ?string,
     *     pending_route?: ?array<string, mixed>
     * }  $extras
     */
    public function append(
        ?string $conversationId,
        array $historyBefore,
        string $userMessage,
        string $assistantMessage,
        ?string $location,
        ?array $lastSearch,
        array $extras = []
    ): void {
        $id = $this->normalizeId($conversationId);
        if ($id === null) {
            return;
        }

        $prev = $this->get($id);
        $turns = $this->sanitizeTurns($historyBefore);
        $turns[] = ['role' => 'user', 'content' => trim($userMessage)];
        $turns[] = ['role' => 'assistant', 'content' => trim($assistantMessage)];

        $max = max(2, (int) config('ask_ai.public_query.history_max_turns', 20));
        $turns = array_slice($turns, -$max);

        $area = array_key_exists('area', $extras)
            ? (is_string($extras['area']) && trim($extras['area']) !== '' ? trim($extras['area']) : null)
            : $prev['area'];
        $pending = array_key_exists('pending_route', $extras)
            ? (is_array($extras['pending_route'] ?? null) ? $extras['pending_route'] : null)
            : $prev['pending_route'];

        $payload = [
            'turns' => $turns,
            'location' => $location ?? $prev['location'],
            'area' => $area,
            'pending_route' => $pending,
            'last_search' => $lastSearch ?? $prev['last_search'],
            'assistant_replies' => $prev['assistant_replies'] + 1,
        ];

        $days = max(1, (int) config('ask_ai.public_query.thread_ttl_days', 7));
        Cache::put($this->key($id), $payload, now()->addDays($days));
    }

    /**
     * @return Thread
     */
    private function empty(): array
    {
        return [
            'turns' => [],
            'location' => null,
            'area' => null,
            'pending_route' => null,
            'last_search' => null,
            'assistant_replies' => 0,
        ];
    }

    /**
     * @param  mixed  $turns
     * @return list<Turn>
     */
    private function sanitizeTurns(mixed $turns): array
    {
        if (! is_array($turns)) {
            return [];
        }

        $out = [];
        foreach ($turns as $turn) {
            if (! is_array($turn)) {
                continue;
            }
            $role = strtolower(trim((string) ($turn['role'] ?? '')));
            $content = trim((string) ($turn['content'] ?? ''));
            if (! in_array($role, ['user', 'assistant'], true) || $content === '') {
                continue;
            }
            $out[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, 4000),
            ];
        }

        $max = max(2, (int) config('ask_ai.public_query.history_max_turns', 20));

        return array_slice($out, -$max);
    }

    private function normalizeId(?string $conversationId): ?string
    {
        if ($conversationId === null) {
            return null;
        }

        $id = trim($conversationId);

        return $id !== '' && strlen($id) <= 64 ? $id : null;
    }

    private function key(string $conversationId): string
    {
        return 'ask_ai:thread:' . $conversationId;
    }
}
