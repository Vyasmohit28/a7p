<?php

declare(strict_types=1);

namespace App\Services\AskAi\Query;

use App\Models\City;
use App\Models\CityArea;
use Throwable;

/**
 * Neighbourhood / locality detection for public Ask AI (separate from city).
 *
 * Search must pass CRM area names via plan `areas[]`, never as `location.city`.
 */
final class AskAiAreaResolver
{
    /**
     * Alias (lowercase) => canonical label shown in chips.
     *
     * @var array<string, string>
     */
    private const CATALOG = [
        'bopal' => 'Bopal',
        'south bopal' => 'South Bopal',
        'alkapuri' => 'Alkapuri',
        'sg highway' => 'SG Highway',
        's.g. highway' => 'SG Highway',
        's g highway' => 'SG Highway',
        'sg road' => 'SG Highway',
        'gotri' => 'Gotri',
        'satellite' => 'Satellite',
        'prahlad nagar' => 'Prahlad Nagar',
        'prahladnagar' => 'Prahlad Nagar',
        'bodakdev' => 'Bodakdev',
        'thaltej' => 'Thaltej',
        'navrangpura' => 'Navrangpura',
        'chandkheda' => 'Chandkheda',
        'gota' => 'Gota',
        'shilaj' => 'Shilaj',
        'sindhu bhavan' => 'Sindhu Bhavan',
        'vesu' => 'Vesu',
        'adajan' => 'Adajan',
        'manjalpur' => 'Manjalpur',
        'karelibaug' => 'Karelibaug',
        'kareli baug' => 'Karelibaug',
        'althan' => 'Althan',
        'paldi' => 'Paldi',
        'vastrapur' => 'Vastrapur',
        'ambli' => 'Ambli',
        'science city' => 'Science City',
        'makarpura' => 'Makarpura',
        'akota' => 'Akota',
        'fatehgunj' => 'Fatehgunj',
    ];

    /**
     * When the user names a short area, also search related CRM names.
     *
     * @var array<string, list<string>>
     */
    private const RELATED = [
        'Bopal' => ['Bopal', 'South Bopal'],
        'South Bopal' => ['South Bopal', 'Bopal'],
        'SG Highway' => ['SG Highway', 'S.G. Highway', 'S G Highway'],
        'Prahlad Nagar' => ['Prahlad Nagar', 'Prahladnagar'],
        'Karelibaug' => ['Karelibaug', 'Kareli Baug'],
    ];

    /**
     * Area named in free text, or null when none matched.
     */
    public function fromMessage(string $message): ?string
    {
        $lower = mb_strtolower($message);
        $keys = array_keys(self::CATALOG);
        usort($keys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($keys as $key) {
            if (preg_match('/\b' . preg_quote($key, '/') . '\b/u', $lower) === 1) {
                return self::CATALOG[$key];
            }
        }

        // "in bopal area" / "bopal area" when catalog word-boundary missed punctuation variants.
        if (preg_match('/\b([a-z][a-z\s\.]{1,40}?)\s+area\b/u', $lower, $m) === 1) {
            $candidate = trim($m[1]);
            if (isset(self::CATALOG[$candidate])) {
                return self::CATALOG[$candidate];
            }
        }

        return null;
    }

    /**
     * Free-text locality / landmark when not in the CRM area catalog.
     * Matched against public_address / area / title (never location.city).
     */
    public function freeTextFromMessage(string $message, ?string $resolvedCity = null): ?string
    {
        if ($this->fromMessage($message) !== null) {
            return null;
        }

        $lower = mb_strtolower($message);
        $cityStop = '';
        if ($resolvedCity !== null && trim($resolvedCity) !== '') {
            $cityStop = preg_quote(mb_strtolower(trim($resolvedCity)), '/');
        }
        $cityAlt = 'ahmedabad|vadodara|surat|rajkot|gandhinagar|baroda|amdavad';
        if ($cityStop !== '') {
            $cityAlt .= '|' . $cityStop;
        }

        // near / around / in / at <phrase>
        if (preg_match(
            '/\b(?:near|around|close to|beside|behind|opposite|in|at)\s+([a-z0-9][a-z0-9\s\.\-]{1,48}?)(?:\s+(?:' . $cityAlt . ')\b|\s+area\b|[?!,.]|$)/u',
            $lower,
            $m
        ) === 1) {
            $phrase = trim($m[1], " \t\n\r\0\x0B.,");
            $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? $phrase;
            if ($this->isNoiseLocalityPhrase($phrase)) {
                return null;
            }

            return mb_convert_case($phrase, MB_CASE_TITLE, 'UTF-8');
        }

        return null;
    }

    private function isNoiseLocalityPhrase(string $phrase): bool
    {
        $p = mb_strtolower(trim($phrase));
        if ($p === '' || mb_strlen($p) < 3) {
            return true;
        }

        $noise = [
            'rent', 'sale', 'buy', 'bhk', 'rk', 'flat', 'flats', 'apartment', 'property',
            'properties', 'house', 'villa', 'budget', 'under', 'best', 'good', 'family',
            'ahmedabad', 'vadodara', 'surat', 'rajkot', 'gandhinagar', 'baroda',
        ];

        return in_array($p, $noise, true)
            || (bool) preg_match('/^\d+\s*(bhk|rk|lakh|lac|cr|k)?$/u', $p);
    }

    /**
     * Normalize an LLM / sticky area token to a catalog label when known.
     */
    public function normalize(?string $area): ?string
    {
        if ($area === null) {
            return null;
        }

        $trimmed = trim($area);
        if ($trimmed === '') {
            return null;
        }

        // Strip trailing city: "Bopal, Ahmedabad" → "Bopal"
        if (str_contains($trimmed, ',')) {
            $trimmed = trim(explode(',', $trimmed, 2)[0]);
        }

        $key = mb_strtolower($trimmed);
        if (isset(self::CATALOG[$key])) {
            return self::CATALOG[$key];
        }

        return mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8');
    }

    public static function isCatalogArea(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }
        if (str_contains($trimmed, ',')) {
            $trimmed = trim(explode(',', $trimmed, 2)[0]);
        }

        return isset(self::CATALOG[mb_strtolower($trimmed)]);
    }

    /**
     * CRM `areas[]` tokens for AskAiQueryExecutor / public list filters.
     *
     * @return list<string>
     */
    public function expandForSearch(?string $areaLabel, ?string $city = null): array
    {
        $label = $this->normalize($areaLabel);
        if ($label === null) {
            return [];
        }

        $names = self::RELATED[$label] ?? [$label];
        $needle = mb_strtolower($label);

        try {
            $query = CityArea::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(area_name) LIKE ?', ['%' . $needle . '%']);

            if ($city !== null && trim($city) !== '') {
                $cityRow = City::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($city))])
                    ->first();
                if ($cityRow !== null) {
                    $query->where('city_id', (int) $cityRow->id);
                }
            }

            foreach ($query->limit(25)->pluck('area_name') as $dbName) {
                $dbName = trim((string) $dbName);
                if ($dbName !== '') {
                    $names[] = $dbName;
                }
            }
        } catch (Throwable) {
            // Tests / misconfigured DB: static catalog is enough.
        }

        $out = [];
        $seen = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $name;
        }

        return $out;
    }
}
