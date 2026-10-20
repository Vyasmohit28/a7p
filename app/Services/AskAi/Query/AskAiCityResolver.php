<?php

declare(strict_types=1);

namespace App\Services\AskAi\Query;

/**
 * Canonical Gujarat city names + aliases for Ask AI public query.
 *
 * Priority when resolving a turn location:
 * 1. City named in the user message
 * 2. Else `city` form hint
 * 3. Else sticky conversation location (caller supplies)
 */
final class AskAiCityResolver
{
    /** @var array<string, string> alias/normalized key => canonical label */
    private const CITIES = [
        'ahmedabad' => 'Ahmedabad',
        'ahmadabad' => 'Ahmedabad',
        'amdavad' => 'Ahmedabad',
        'vadodara' => 'Vadodara',
        'baroda' => 'Vadodara',
        'surat' => 'Surat',
        'rajkot' => 'Rajkot',
        'gandhinagar' => 'Gandhinagar',
        'bhavnagar' => 'Bhavnagar',
        'jamnagar' => 'Jamnagar',
    ];

    /**
     * City named in free text, or null when none matched.
     */
    public function fromMessage(string $message): ?string
    {
        $lower = mb_strtolower($message);

        // Longer aliases first so "gandhinagar" wins before accidental partials.
        $keys = array_keys(self::CITIES);
        usort($keys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($keys as $key) {
            if (preg_match('/\b' . preg_quote($key, '/') . '\b/u', $lower) === 1) {
                return self::CITIES[$key];
            }
        }

        return null;
    }

    /**
     * Normalize a hint / sticky value to a canonical city label when known.
     *
     * Rejects locality composites such as "Bopal, Ahmedabad" — those are not cities.
     * Returns the city segment when present; returns null for known area-only strings.
     */
    public function normalize(?string $city): ?string
    {
        if ($city === null) {
            return null;
        }

        $trimmed = trim($city);
        if ($trimmed === '') {
            return null;
        }

        // "Bopal, Ahmedabad" / "Ahmedabad, Gujarat" → pick a known city segment.
        if (str_contains($trimmed, ',')) {
            $parts = array_map('trim', explode(',', $trimmed));
            foreach (array_reverse($parts) as $part) {
                $canonical = $this->normalizeSimple($part);
                if ($canonical !== null && $this->isKnownCity($canonical)) {
                    return $canonical;
                }
            }
            foreach ($parts as $part) {
                $canonical = $this->normalizeSimple($part);
                if ($canonical !== null && $this->isKnownCity($canonical)) {
                    return $canonical;
                }
            }

            // Composite with no known city — do not use as location.city.
            return null;
        }

        $simple = $this->normalizeSimple($trimmed);
        if ($simple === null) {
            return null;
        }

        // Area-only tokens must not become the search city (empty inventory match).
        if (! $this->isKnownCity($simple) && AskAiAreaResolver::isCatalogArea($trimmed)) {
            return null;
        }

        return $simple;
    }

    private function normalizeSimple(string $trimmed): ?string
    {
        $trimmed = trim($trimmed);
        if ($trimmed === '') {
            return null;
        }

        $key = mb_strtolower($trimmed);
        if (isset(self::CITIES[$key])) {
            return self::CITIES[$key];
        }

        return mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8');
    }

    private function isKnownCity(string $label): bool
    {
        return in_array($label, array_values(self::CITIES), true);
    }

    /**
     * Resolve location for this turn.
     *
     * @param  ?string  $stickyLocation  Prior conversation filter location (already canonical if possible)
     */
    public function resolve(string $message, ?string $cityHint, ?string $stickyLocation = null): ?string
    {
        $fromMessage = $this->fromMessage($message);
        if ($fromMessage !== null) {
            return $fromMessage;
        }

        $fromHint = $this->normalize($cityHint);
        if ($fromHint !== null) {
            return $fromHint;
        }

        return $this->normalize($stickyLocation);
    }
}
