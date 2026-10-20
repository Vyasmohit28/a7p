<?php

namespace App\Services\AskAi;

/**
 * Allowlists and normalizes an untrusted SearchPlan draft.
 * Hard requirements are never silently dropped.
 */
class AskAiSearchPlanValidator
{
    public const MODES = [
        'structured_search',
        'exact_property_search',
        'location_search',
        'nearby_search',
        'fallback_text_search',
        'detail_lookup',
    ];

    public const BHK_TOKENS = [
        '1RK',
        '1BHK',
        '1.5BHK',
        '2BHK',
        '2.5BHK',
        '3BHK',
        '3.5BHK',
        '4BHK',
        '5BHK+',
        '4BHK+',
        'Office',
        'Shop',
        'PG',
    ];

    public const FURNISHING = ['furnished', 'semi_furnished', 'unfurnished'];

    public const AVAILABLE_FOR = ['family', 'executive', 'bachelors', 'couple'];

    public const BACHELOR = ['boys', 'girls', 'both'];

    public const SORTS = ['price_low', 'price_high', 'most_viewed', 'most_liked'];

    /** Max parameter constraints on one SearchPlan. */
    public const PARAMETER_MAX_ITEMS = 20;

    /** Max accepted values for one parameter constraint. */
    public const PARAMETER_VALUES_MAX = 20;

    /** assign_parameters.value is a string column. */
    public const PARAMETER_VALUE_MAX_LENGTH = 255;

    /**
     * @param  array<string, mixed>  $draft
     * @return array{
     *     plan: array<string, mixed>,
     *     unsupported: list<string>,
     *     clarifications: list<array<string, mixed>>,
     *     hard: list<string>,
     *     status: string
     * }
     */
    public function validate(array $draft): array
    {
        $unsupported = [];
        $clarifications = [];
        $hard = $this->normalizeStringList($draft['hard'] ?? []);

        $forbiddenTopKeys = [
            'filters', 'owner_name', 'owner_phone', 'owner_email', 'owner_whatsapp',
            'assigned_to', 'crm_internal_notes', 'property_address', 'sql', 'query',
        ];
        foreach ($forbiddenTopKeys as $key) {
            if (array_key_exists($key, $draft)) {
                $unsupported[] = $key;
            }
        }

        $mode = (string) ($draft['mode'] ?? 'structured_search');
        if (! in_array($mode, self::MODES, true)) {
            $mode = 'structured_search';
        }

        $intent = (string) ($draft['intent'] ?? 'search');
        if (! in_array($intent, ['search', 'detail', 'clarify', 'unsupported'], true)) {
            $intent = 'search';
        }

        $propertyType = $draft['property_type'] ?? null;
        if ($propertyType !== null && $propertyType !== '') {
            if (! in_array((int) $propertyType, [0, 1], true) && ! in_array((string) $propertyType, ['0', '1'], true)) {
                $unsupported[] = 'property_type';
                $propertyType = null;
            } else {
                $propertyType = (int) $propertyType;
            }
        } else {
            $propertyType = null;
        }

        $crmCategory = $draft['crm_category'] ?? null;
        if ($crmCategory !== null && $crmCategory !== '') {
            $crmCategory = (string) $crmCategory;
            if (! in_array($crmCategory, ['residential', 'commercial'], true)) {
                $unsupported[] = 'crm_category';
                $crmCategory = null;
            }
        } else {
            $crmCategory = null;
        }

        $categoryId = isset($draft['category_id']) && $draft['category_id'] !== '' && $draft['category_id'] !== null
            ? (int) $draft['category_id']
            : null;
        if ($categoryId !== null && $categoryId <= 0) {
            $categoryId = null;
        }

        $categorySlugId = filled($draft['category_slug_id'] ?? null)
            ? (string) $draft['category_slug_id']
            : null;

        $location = $this->normalizeLocation(is_array($draft['location'] ?? null) ? $draft['location'] : [], $unsupported);
        $entity = $this->normalizeEntity(is_array($draft['entity'] ?? null) ? $draft['entity'] : [], $unsupported);

        // place_id is syntactically valid on SearchPlan but NOT a property-query filter.
        // Properties have no place_id column; do not geocode or call Maps APIs from Ask AI.
        if (filled($location['place_id'] ?? null)) {
            $unsupported[] = 'location.place_id';
            $placeHard = 'location.place_id:' . (string) $location['place_id'];
            if (! $this->hardContainsPlaceId($hard)) {
                $hard[] = $placeHard;
            }
            $this->retainHard($hard, 'location.place_id', $unsupported);
        }

        if (($entity['kind'] ?? null) === 'society') {
            $unsupported[] = 'entity.society';
            $entity['kind'] = 'unknown';
            $this->retainHard($hard, 'entity.society', $unsupported);
        }

        $areas = $this->normalizeAreas($draft['areas'] ?? [], $unsupported);
        $bhk = $this->normalizeAllowlist($draft['bhk'] ?? [], self::BHK_TOKENS, 'bhk', $unsupported);
        $furnishing = $this->normalizeAllowlist(
            $draft['furnishing_status'] ?? [],
            self::FURNISHING,
            'furnishing_status',
            $unsupported
        );
        $availableFor = $this->normalizeAllowlist(
            $draft['available_for'] ?? [],
            self::AVAILABLE_FOR,
            'available_for',
            $unsupported
        );
        $bachelor = $this->normalizeAllowlist(
            is_array($draft['bachelor_type'] ?? null)
                ? $draft['bachelor_type']
                : (filled($draft['bachelor_type'] ?? null) ? [$draft['bachelor_type']] : []),
            self::BACHELOR,
            'bachelor_type',
            $unsupported
        );

        $price = $this->normalizePrice(is_array($draft['price'] ?? null) ? $draft['price'] : []);
        $parameters = $this->normalizeParameters($draft['parameters'] ?? [], $unsupported);
        $nearby = $this->normalizeNearby($draft['nearby_places'] ?? [], $unsupported);

        $postedSince = $draft['posted_since'] ?? null;
        if ($postedSince !== null && $postedSince !== '') {
            $postedSince = (int) $postedSince;
            if ($postedSince === 0) {
                $unsupported[] = 'posted_since';
                $this->retainHard($hard, 'posted_since', $unsupported);
                $postedSince = null;
            } elseif (! in_array($postedSince, [1, 2, 3, 4], true)) {
                $unsupported[] = 'posted_since';
                $postedSince = null;
            }
        } else {
            $postedSince = null;
        }

        $sort = filled($draft['sort'] ?? null) ? (string) $draft['sort'] : null;
        if ($sort !== null && ! in_array($sort, self::SORTS, true)) {
            $unsupported[] = 'sort';
            $sort = null;
        }

        $flags = [
            'promoted' => $this->truthyFlag(($draft['flags']['promoted'] ?? null)),
            'smart_pricing_enabled' => $this->truthyFlag(
                $draft['flags']['smart_pricing_enabled'] ?? $draft['smart_pricing_enabled'] ?? null
            ),
        ];

        $hasPhotos = $draft['has_photos'] ?? null;
        if ($hasPhotos === true || $hasPhotos === 1 || $hasPhotos === '1' || $hasPhotos === 'true') {
            $unsupported[] = 'has_photos';
            $this->retainHard($hard, 'has_photos', $unsupported);
            $hasPhotos = true;
        } else {
            $hasPhotos = null;
        }

        if (array_key_exists('description_query', $draft) || array_key_exists('description', $draft)) {
            $unsupported[] = 'description_search';
            $this->retainHard($hard, 'description_search', $unsupported);
        }

        $textFallback = filled($draft['text_fallback'] ?? null)
            ? mb_substr(trim((string) $draft['text_fallback']), 0, 1000)
            : null;

        // Public listing address / landmark phrase (LIKE on area, public_address, title).
        $addressContains = null;
        if (filled($draft['address_contains'] ?? null) && is_scalar($draft['address_contains'])) {
            $rawAddress = trim((string) $draft['address_contains']);
            $rawAddress = str_replace(['%', '_'], '', $rawAddress);
            if ($rawAddress !== '' && mb_strlen($rawAddress) >= 2) {
                $addressContains = mb_substr($rawAddress, 0, 100);
            }
        }

        $needsClarification = $this->normalizeStringList($draft['needs_clarification'] ?? []);
        foreach ($needsClarification as $item) {
            $clarifications[] = [
                'id' => $item,
                'prompt' => $item,
            ];
        }

        // Exact entity without identifiers → clarification
        if ($mode === 'exact_property_search'
            && blank($entity['social_id'] ?? null)
            && blank($entity['slug_id'] ?? null)
            && blank($entity['title'] ?? null)
            && blank($entity['public_address'] ?? null)
        ) {
            $clarifications[] = [
                'id' => 'exact_property_identity',
                'prompt' => 'Which property should I look up? Share a social ID, slug, or exact title.',
            ];
        }

        $plan = [
            'intent' => $intent,
            'mode' => $mode,
            'property_type' => $propertyType,
            'crm_category' => $crmCategory,
            'category_id' => $categoryId,
            'category_slug_id' => $categorySlugId,
            'location' => $location,
            'entity' => $entity,
            'areas' => $areas,
            'bhk' => $bhk,
            'price' => $price,
            'furnishing_status' => $furnishing,
            'available_for' => $availableFor,
            'bachelor_type' => $bachelor,
            'parameters' => $parameters,
            'nearby_places' => $nearby,
            'posted_since' => $postedSince,
            'sort' => $sort,
            'flags' => $flags,
            'has_photos' => $hasPhotos,
            'text_fallback' => $textFallback,
            'address_contains' => $addressContains,
            'hard' => $hard,
            'soft' => $this->normalizeStringList($draft['soft'] ?? []),
            'needs_clarification' => $needsClarification,
        ];

        $unsupported = array_values(array_unique($unsupported));

        $status = 'validated';
        if ($unsupported !== []) {
            $status = 'partial';
        }
        if ($clarifications !== [] && $intent === 'clarify') {
            $status = 'partial';
        }

        // Hard unsupported without user relaxation → still partial, not applied
        foreach ($hard as $hardKey) {
            if (in_array($hardKey, $unsupported, true)
                || str_starts_with($hardKey, 'has_photos')
                || str_starts_with($hardKey, 'location.place_id')
                || str_starts_with($hardKey, 'place_id')
            ) {
                $status = 'partial';
            }
        }

        return [
            'plan' => $plan,
            'unsupported' => $unsupported,
            'clarifications' => $clarifications,
            'hard' => $hard,
            'status' => $status,
        ];
    }

    /**
     * @param  list<string>  $hard
     */
    private function hardContainsPlaceId(array $hard): bool
    {
        foreach ($hard as $item) {
            $item = (string) $item;
            if ($item === 'location.place_id'
                || $item === 'place_id'
                || str_starts_with($item, 'location.place_id:')
                || str_starts_with($item, 'place_id:')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $hard
     * @param  list<string>  $unsupported
     */
    private function retainHard(array &$hard, string $key, array &$unsupported): void
    {
        if (! in_array($key, $hard, true)) {
            // Keep hard list as provided; ensure unsupported lists the key
        }
        if (! in_array($key, $unsupported, true)) {
            $unsupported[] = $key;
        }
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  list<string>  $unsupported
     * @return array<string, mixed>
     */
    private function normalizeLocation(array $location, array &$unsupported = []): array
    {
        $allowed = ['city', 'state', 'country', 'place_id', 'latitude', 'longitude', 'range', 'range_km'];
        foreach (array_keys($location) as $key) {
            $key = (string) $key;
            if (! in_array($key, $allowed, true)) {
                // Reject private address / invented discovery fields (no location.address search).
                $unsupported[] = 'location.' . $key;
            }
        }

        $city = null;
        if ($this->isNonEmptyScalar($location['city'] ?? null)) {
            $city = mb_substr(trim((string) $location['city']), 0, 100);
        } elseif (array_key_exists('city', $location) && $location['city'] !== null && $location['city'] !== '') {
            $unsupported[] = 'location.city';
        }

        $state = null;
        if ($this->isNonEmptyScalar($location['state'] ?? null)) {
            $state = mb_substr(trim((string) $location['state']), 0, 100);
        } elseif (array_key_exists('state', $location) && $location['state'] !== null && $location['state'] !== '') {
            $unsupported[] = 'location.state';
        }

        $country = null;
        if ($this->isNonEmptyScalar($location['country'] ?? null)) {
            $country = mb_substr(trim((string) $location['country']), 0, 100);
        } elseif (array_key_exists('country', $location) && $location['country'] !== null && $location['country'] !== '') {
            $unsupported[] = 'location.country';
        }

        $placeId = null;
        if ($this->isNonEmptyScalar($location['place_id'] ?? null)) {
            $placeId = mb_substr(trim((string) $location['place_id']), 0, 255);
        } elseif (array_key_exists('place_id', $location) && $location['place_id'] !== null && $location['place_id'] !== '') {
            $unsupported[] = 'location.place_id';
        }

        $latitude = $this->normalizeCoordinate($location['latitude'] ?? null);
        $longitude = $this->normalizeCoordinate($location['longitude'] ?? null);

        // Incomplete or zero-only pairs must not become a location constraint.
        if ($latitude === null || $longitude === null || $latitude == 0.0 || $longitude == 0.0) {
            $latitude = null;
            $longitude = null;
        }

        $range = null;
        if ($latitude !== null && $longitude !== null) {
            if (isset($location['range']) && $location['range'] !== '' && $location['range'] !== null) {
                $range = min(50.0, max(0.1, (float) $location['range']));
            } elseif (isset($location['range_km']) && $location['range_km'] !== '' && $location['range_km'] !== null) {
                $range = min(50.0, max(0.1, (float) $location['range_km']));
            }
        }

        return [
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'place_id' => $placeId,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'range' => $range,
        ];
    }

    private function normalizeCoordinate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Exact area / locality tokens for propertys.area and area_localities JSON.
     *
     * @param  mixed  $raw
     * @param  list<string>  $unsupported
     * @return list<string>
     */
    private function normalizeAreas(mixed $raw, array &$unsupported): array
    {
        if (! is_array($raw)) {
            if ($this->isNonEmptyScalar($raw)) {
                return [mb_substr(trim((string) $raw), 0, 100)];
            }
            if ($raw !== null && $raw !== '') {
                $unsupported[] = 'areas';
            }

            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if ($this->isNonEmptyScalar($item)) {
                $out[] = mb_substr(trim((string) $item), 0, 100);
                continue;
            }
            if ($item !== null && $item !== '') {
                $unsupported[] = 'areas';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $entity
     * @param  list<string>  $unsupported
     * @return array<string, mixed>
     */
    private function normalizeEntity(array $entity, array &$unsupported = []): array
    {
        foreach (array_keys($entity) as $key) {
            $key = (string) $key;
            if (in_array($key, ['kind', 'social_id', 'slug_id', 'title', 'public_address'], true)) {
                continue;
            }
            // Reject internal IDs / private address / operator-shaped keys.
            $unsupported[] = 'entity.' . $key;
        }

        $kind = (string) ($entity['kind'] ?? 'property');
        if (! in_array($kind, ['property', 'society', 'unknown'], true)) {
            $kind = 'property';
        }

        $socialId = null;
        if ($this->isNonEmptyScalar($entity['social_id'] ?? null)) {
            $rawSocial = mb_substr(trim((string) $entity['social_id']), 0, 32);
            $normalized = \App\Services\PublicPropertyService::normalizeSocialId($rawSocial);
            // Canonical HT-#### when format matches; otherwise keep trimmed exact input.
            $socialId = $normalized ?? $rawSocial;
        } elseif (array_key_exists('social_id', $entity) && $entity['social_id'] !== null && $entity['social_id'] !== '') {
            $unsupported[] = 'entity.social_id';
        }

        $slugId = null;
        if ($this->isNonEmptyScalar($entity['slug_id'] ?? null)) {
            $slugId = mb_substr(trim((string) $entity['slug_id']), 0, 255);
        } elseif (array_key_exists('slug_id', $entity) && $entity['slug_id'] !== null && $entity['slug_id'] !== '') {
            $unsupported[] = 'entity.slug_id';
        }

        $title = null;
        if ($this->isNonEmptyScalar($entity['title'] ?? null)) {
            $title = mb_substr(trim((string) $entity['title']), 0, 255);
        } elseif (array_key_exists('title', $entity) && $entity['title'] !== null && $entity['title'] !== '') {
            $unsupported[] = 'entity.title';
        }

        $publicAddress = null;
        if ($this->isNonEmptyScalar($entity['public_address'] ?? null)) {
            $publicAddress = mb_substr(trim((string) $entity['public_address']), 0, 255);
        } elseif (array_key_exists('public_address', $entity) && $entity['public_address'] !== null && $entity['public_address'] !== '') {
            $unsupported[] = 'entity.public_address';
        }

        return [
            'kind' => $kind,
            'social_id' => $socialId,
            'slug_id' => $slugId,
            'title' => $title,
            'public_address' => $publicAddress,
        ];
    }

    private function isNonEmptyScalar(mixed $value): bool
    {
        return (is_string($value) || is_int($value) || is_float($value))
            && trim((string) $value) !== '';
    }

    /**
     * @param  array<string, mixed>  $price
     * @return array{min: ?float, max: ?float}
     */
    private function normalizePrice(array $price): array
    {
        $min = $price['min'] ?? $price['min_price'] ?? null;
        $max = $price['max'] ?? $price['max_price'] ?? null;

        // Open bounds must be null — never 0 (matches list API empty(0) pitfall avoidance).
        $min = ($min === null || $min === '' || (is_numeric($min) && (float) $min == 0.0))
            ? null
            : max(0, (float) $min);
        $max = ($max === null || $max === '' || (is_numeric($max) && (float) $max == 0.0))
            ? null
            : max(0, (float) $max);

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * @param  mixed  $raw
     * @param  list<string>  $allow
     * @param  list<string>  $unsupported
     * @return list<string>
     */
    private function normalizeAllowlist(mixed $raw, array $allow, string $field, array &$unsupported): array
    {
        $values = $this->normalizeStringList($raw);
        $out = [];
        foreach ($values as $value) {
            if (in_array($value, $allow, true)) {
                $out[] = $value;
            } else {
                $unsupported[] = $field . ':' . $value;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Canonical Ask AI parameter filter: {id, value?}.
     *
     * Real schema: parameters.id + assign_parameters.value (string / JSON string).
     * There is no parameter_value_id table. Names are ignored for querying.
     *
     * @param  mixed  $raw
     * @param  list<string>  $unsupported
     * @return list<array{id: int, value: null|string|list<string>}>
     */
    private function normalizeParameters(mixed $raw, array &$unsupported): array
    {
        if (! is_array($raw)) {
            if ($raw !== null && $raw !== '') {
                $unsupported[] = 'parameters';
            }

            return [];
        }

        if ($raw === []) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (count($out) >= self::PARAMETER_MAX_ITEMS) {
                $unsupported[] = 'parameters.limit';
                break;
            }

            if (! is_array($row)) {
                $unsupported[] = 'parameters.malformed';
                continue;
            }

            foreach (array_keys($row) as $key) {
                $key = (string) $key;
                if (in_array($key, ['id', 'value', 'name'], true)) {
                    continue;
                }
                // Reject operator / column / SQL-shaped keys.
                $unsupported[] = 'parameters.' . $key;
            }

            if (! array_key_exists('id', $row) || $row['id'] === null || $row['id'] === '') {
                $unsupported[] = 'parameters.id_required';
                continue;
            }

            if (! is_numeric($row['id']) || (int) $row['id'] != $row['id']) {
                $unsupported[] = 'parameters.id';
                continue;
            }

            $id = (int) $row['id'];
            if ($id <= 0) {
                $unsupported[] = 'parameters.id';
                continue;
            }

            $value = null;
            if (array_key_exists('value', $row) && $row['value'] !== null && $row['value'] !== '') {
                $normalizedValue = $this->normalizeParameterValue($row['value'], $unsupported);
                if ($normalizedValue === null) {
                    // Value was present but invalid — keep id-only filter, mark unsupported.
                    $out[] = ['id' => $id, 'value' => null];
                    continue;
                }
                $value = $normalizedValue;
            }

            // name may remain on the plan for future GPT context; executor never queries by name.
            $out[] = ['id' => $id, 'value' => $value];
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @param  list<string>  $unsupported
     * @return null|string|list<string>
     */
    private function normalizeParameterValue(mixed $raw, array &$unsupported): null|string|array
    {
        if (is_array($raw)) {
            $values = [];
            foreach ($raw as $item) {
                if (count($values) >= self::PARAMETER_VALUES_MAX) {
                    $unsupported[] = 'parameters.value.limit';
                    break;
                }
                if (! is_scalar($item) || (string) $item === '') {
                    $unsupported[] = 'parameters.value';
                    continue;
                }
                $values[] = mb_substr(trim((string) $item), 0, self::PARAMETER_VALUE_MAX_LENGTH);
            }

            $values = array_values(array_unique($values));

            return $values === [] ? null : $values;
        }

        if (! is_scalar($raw)) {
            $unsupported[] = 'parameters.value';

            return null;
        }

        $value = mb_substr(trim((string) $raw), 0, self::PARAMETER_VALUE_MAX_LENGTH);

        return $value === '' ? null : $value;
    }

    /**
     * @param  mixed  $raw
     * @param  list<string>  $unsupported
     * @return list<array{id: int, value: ?int}>
     */
    private function normalizeNearby(mixed $raw, array &$unsupported): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }
            $id = (int) $row['id'];
            if ($id <= 0) {
                continue;
            }
            $value = $row['value'] ?? null;
            $out[] = [
                'id' => $id,
                'value' => ($value === null || $value === '') ? null : (int) $value,
            ];
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            if ($raw === null || $raw === '') {
                return [];
            }

            return [trim((string) $raw)];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            $raw
        ), static fn ($v) => $v !== '')));
    }

    private function truthyFlag(mixed $value): ?bool
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        return null;
    }
}
