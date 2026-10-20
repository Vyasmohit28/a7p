<?php

namespace App\Services\AskAi;

use App\Models\Property;
use App\Services\PublicPropertyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Executes a validated SearchPlan against public listing visibility only.
 * Does not interpret natural language, call Maps, or change get-property-list.
 *
 * Hierarchy: structured filters, then parameter/nearby IDs, then exact identity,
 * then public address, then area/locality. Generic text_fallback is not applied.
 */
class AskAiQueryExecutor
{
    public function __construct(
        protected AskAiSearchQueryContract $contract,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array{total: int, items: Collection<int, Property>}
     */
    public function execute(array $plan, int $limit, int $offset): array
    {
        $maxLimit = (int) config('ask_ai.max_limit', 12);
        $maxOffset = (int) config('ask_ai.max_offset', 500);
        $limit = min($maxLimit, max(1, $limit));
        $offset = max(0, min($maxOffset, $offset));

        $query = $this->compileQuery($plan);
        if ($query === null) {
            // Text-only plans are not a search. Do not scan the catalog.
            return ['total' => 0, 'items' => collect()];
        }

        if (! $this->contract->hasExactIdentity($plan)) {
            $this->applySort($query, $plan);
        }

        // Count before page window. Filters use whereHas / JSON — not row-multiplying joins.
        $total = (clone $query)->count();
        $items = $query
            ->addSelect(PublicPropertyService::LISTING_CARD_COLUMNS)
            ->with(['category:id,category,image,slug_id'])
            ->skip($offset)
            ->take($limit)
            ->get()
            ->unique('id')
            ->values()
            ->take($limit)
            ->each(static function (Property $property): void {
                $property->setAppends([]);
            });

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Query the executor would run. Null when the plan has no deterministic constraint.
     * text_fallback is never part of this query.
     *
     * @param  array<string, mixed>  $plan
     */
    public function compileQuery(array $plan): ?Builder
    {
        if (! $this->contract->isExecutable($plan)) {
            return null;
        }

        if ($this->contract->hasExactIdentity($plan)) {
            return $this->identityQuery($plan);
        }

        return $this->listingQuery($plan);
    }

    /**
     * Structured listing query. Does not apply text_fallback.
     * Cross-dimension predicates are AND. Area is not OR'd with title.
     *
     * @param  array<string, mixed>  $plan
     */
    public function listingQuery(array $plan): Builder
    {
        $query = $this->baseQuery();
        $this->applyStructuredFilters($query, $plan);

        return $query;
    }

    /**
     * Exact identity predicate, then structured filters AND'd on.
     * Priority: social_id, else slug_id, else title, else public_address.
     * Each identity field uses exact equality only (no LIKE % / OR across identity types).
     * Does not search private address, description, society, building, or numeric property id.
     *
     * @param  array<string, mixed>  $plan
     */
    protected function identityQuery(array $plan): ?Builder
    {
        $entity = is_array($plan['entity'] ?? null) ? $plan['entity'] : [];
        $query = $this->baseQuery();

        $socialId = filled($entity['social_id'] ?? null) ? (string) $entity['social_id'] : null;
        if ($socialId !== null) {
            // Prefer canonical HT-#### from PublicPropertyService; otherwise exact raw equality.
            $normalized = PublicPropertyService::normalizeSocialId($socialId);
            $query->where('social_id', $normalized ?? $socialId);
        } elseif (filled($entity['slug_id'] ?? null)) {
            $query->where('slug_id', (string) $entity['slug_id']);
        } elseif (filled($entity['title'] ?? null)) {
            $query->where('title', (string) $entity['title']);
        } elseif (filled($entity['public_address'] ?? null)) {
            $query->where('public_address', (string) $entity['public_address']);
        } else {
            return null;
        }

        $this->applyStructuredFilters($query, $plan);
        $query->orderByDesc('id');

        return $query;
    }

    protected function baseQuery(): Builder
    {
        return Property::query()
            ->whereIn('propery_type', [0, 1])
            ->where(function (Builder $query): void {
                $query->onlyActive();
            });
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function applyStructuredFilters(Builder $query, array $plan): void
    {
        if ($plan['property_type'] !== null && $plan['property_type'] !== '') {
            $query->where('propery_type', (int) $plan['property_type']);
        }

        if (! empty($plan['category_id'])) {
            $query->where('category_id', (int) $plan['category_id']);
        }

        if (! empty($plan['category_slug_id'])) {
            $slug = (string) $plan['category_slug_id'];
            $query->whereHas('category', function (Builder $categoryQuery) use ($slug): void {
                $categoryQuery->where('slug_id', $slug);
            });
        }

        if (! empty($plan['crm_category'])) {
            $query->where('crm_category', (string) $plan['crm_category']);
        }

        $location = is_array($plan['location'] ?? null) ? $plan['location'] : [];
        if (! empty($location['country'])) {
            $query->where('country', 'like', '%' . $location['country'] . '%');
        }
        if (! empty($location['state'])) {
            $query->where('state', 'like', '%' . $location['state'] . '%');
        }
        if (! empty($location['city'])) {
            $query->where('city', 'like', '%' . $location['city'] . '%');
        }

        // Area/locality is a separate dimension from public_address and from city.
        // Exact: propertys.area IN list OR exact JSON element in area_localities.
        // Multiple area tokens are OR within this dimension only (not OR'd with address/title).
        $areas = is_array($plan['areas'] ?? null) ? $plan['areas'] : [];
        if ($areas !== []) {
            $query->where(function (Builder $builder) use ($areas): void {
                $builder->whereIn('area', $areas);
                foreach ($areas as $areaName) {
                    $safe = str_replace(['%', '_'], '', (string) $areaName);
                    $builder->orWhereJsonContains('area_localities', $areaName);
                    // Substring on area so “Bopal” also hits “South Bopal”.
                    if ($safe !== '') {
                        $builder->orWhere('area', 'like', '%' . $safe . '%');
                    }
                }
            });
        }

        // Free-text / landmark phrase against the same public fields the website list exposes.
        $addressContains = filled($plan['address_contains'] ?? null)
            ? trim((string) $plan['address_contains'])
            : '';
        if ($addressContains !== '') {
            $term = str_replace(['%', '_'], '', mb_substr($addressContains, 0, 100));
            if ($term !== '') {
                $query->where(function (Builder $builder) use ($term): void {
                    $builder->where('area', 'like', '%' . $term . '%')
                        ->orWhere('public_address', 'like', '%' . $term . '%')
                        ->orWhere('title', 'like', '%' . $term . '%');
                });
            }
        }

        $bhk = is_array($plan['bhk'] ?? null) ? $plan['bhk'] : [];
        if ($bhk !== []) {
            $this->applyBhkFilters($query, $bhk);
        }

        $availableFor = is_array($plan['available_for'] ?? null) ? $plan['available_for'] : [];
        if ($availableFor !== []) {
            $query->where(function (Builder $builder) use ($availableFor): void {
                foreach ($availableFor as $option) {
                    $builder->orWhereJsonContains('available_for_options', (string) $option);
                }
            });
        }

        $bachelor = is_array($plan['bachelor_type'] ?? null) ? $plan['bachelor_type'] : [];
        if ($bachelor !== []) {
            $query->where(function (Builder $builder) use ($bachelor): void {
                foreach ($bachelor as $bachelorTypeFilter) {
                    if ($bachelorTypeFilter === 'boys') {
                        $builder->orWhereIn('bachelor_type', ['boys', 'both']);
                    } elseif ($bachelorTypeFilter === 'girls') {
                        $builder->orWhereIn('bachelor_type', ['girls', 'both']);
                    } else {
                        $builder->orWhere('bachelor_type', $bachelorTypeFilter);
                    }
                }
            });
        }

        $furnishing = is_array($plan['furnishing_status'] ?? null) ? $plan['furnishing_status'] : [];
        if ($furnishing !== []) {
            $query->whereIn('furnishing_status', $furnishing);
        }

        $min = $plan['price']['min'] ?? null;
        $max = $plan['price']['max'] ?? null;
        if ($min !== null) {
            $query->where('price', '>=', $min);
        }
        if ($max !== null) {
            $query->where('price', '<=', $max);
        }

        $parameters = is_array($plan['parameters'] ?? null) ? $plan['parameters'] : [];
        foreach ($parameters as $parameter) {
            $parameterId = (int) ($parameter['id'] ?? 0);
            if ($parameterId <= 0) {
                continue;
            }

            $valueCandidates = $this->parameterValueCandidates($parameter['value'] ?? null);

            $query->whereHas('assignParameter', function (Builder $parameterQuery) use ($parameterId, $valueCandidates): void {
                $parameterQuery->where('parameter_id', $parameterId);
                if ($valueCandidates === []) {
                    // ID presence only — no value predicate.
                    return;
                }

                // Exact match against assign_parameters.value (string column). Bound values only.
                $parameterQuery->where(function (Builder $valueQuery) use ($valueCandidates): void {
                    foreach ($valueCandidates as $candidate) {
                        $valueQuery->orWhere('value', $candidate);
                    }
                });
            });
        }

        $nearbyPlaces = is_array($plan['nearby_places'] ?? null) ? $plan['nearby_places'] : [];
        foreach ($nearbyPlaces as $nearbyPlace) {
            $nearbyPlaceId = (int) ($nearbyPlace['id'] ?? 0);
            if ($nearbyPlaceId <= 0) {
                continue;
            }
            $nearbyPlaceValue = $nearbyPlace['value'] ?? null;
            if ($nearbyPlaceValue !== null && $nearbyPlaceValue !== '' && (int) $nearbyPlaceValue > 0) {
                $distance = (int) $nearbyPlaceValue;
                $query->whereHas('assignfacilities', function (Builder $facilityQuery) use ($nearbyPlaceId, $distance): void {
                    $facilityQuery->where('facility_id', $nearbyPlaceId)->where('distance', '<=', $distance);
                });
            } else {
                $query->whereHas('assignfacilities', function (Builder $facilityQuery) use ($nearbyPlaceId): void {
                    $facilityQuery->where('facility_id', $nearbyPlaceId);
                });
            }
        }

        if (! empty($plan['flags']['smart_pricing_enabled'])) {
            $query->where('smart_pricing_enabled', 1);
        }

        if (! empty($plan['flags']['promoted'])) {
            $query->whereHas('advertisement', function (Builder $adQuery): void {
                $adQuery->where(['status' => 0, 'is_enable' => 1]);
            });
        }

        $postedSince = $plan['posted_since'] ?? null;
        if (in_array((int) $postedSince, [1, 2, 3, 4], true)) {
            $postedSince = (int) $postedSince;
            if ($postedSince === 1) {
                $query->whereDate('created_at', Carbon::yesterday());
            } elseif ($postedSince === 2) {
                $query->whereBetween('created_at', [Carbon::now()->subMonth(), Carbon::now()->endOfDay()]);
            } elseif ($postedSince === 3) {
                $query->whereBetween('created_at', [Carbon::now()->subMonths(3), Carbon::now()->endOfDay()]);
            } elseif ($postedSince === 4) {
                $query->whereBetween('created_at', [Carbon::now()->subMonths(6), Carbon::now()->endOfDay()]);
            }
        }

        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;
        $range = $location['range'] ?? null;
        // Require both coordinates. Incomplete pairs must not invent a location filter.
        if ($latitude !== null && $longitude !== null && $latitude != 0 && $longitude != 0) {
            if ($range !== null && (float) $range > 0) {
                $query->selectRaw(
                    '(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance',
                    [$latitude, $longitude, $latitude]
                )
                    ->where('latitude', '!=', 0)
                    ->where('longitude', '!=', 0)
                    ->having('distance', '<', $range);
            } else {
                $query->where('latitude', $latitude)->where('longitude', $longitude);
            }
        }

        // has_photos is never applied here — must remain unsupported until a real filter ships.
        // location.place_id is never applied here — properties have no place_id column and
        // Ask AI must not geocode or call Maps APIs. Hard place_id is blocked before execute.
        // Contains/LIKE public_address discovery is deferred; exact address identity uses entity.public_address.
    }

    /**
     * Expand a SearchPlan parameter value into exact DB storage candidates.
     *
     * assign_parameters.value is a string column. Writers may store plain text or
     * htmlspecialchars(ENT_QUOTES) text. Checkbox multi-select may be stored as a
     * full JSON_FORCE_OBJECT string — only full-string equality is attempted here
     * (no LIKE / JSON_CONTAINS partial match).
     *
     * @return list<string>
     */
    protected function parameterValueCandidates(mixed $raw): array
    {
        $values = [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_scalar($item) && (string) $item !== '') {
                    $values[] = (string) $item;
                }
            }
        } elseif (is_scalar($raw) && (string) $raw !== '') {
            $values[] = (string) $raw;
        }

        $candidates = [];
        foreach ($values as $value) {
            $candidates[] = $value;
            $encoded = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            if ($encoded !== $value) {
                $candidates[] = $encoded;
            }
            $decoded = htmlspecialchars_decode($value, ENT_QUOTES);
            if ($decoded !== $value) {
                $candidates[] = $decoded;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Ask AI BHK accuracy (Stage 2C Step 3).
     *
     * Storage: JSON `availability_options` string tokens (e.g. 2BHK, 2.5BHK) plus
     * integer `bhk_count` (unsigned tinyint — cannot store .5). Public list BHK
     * mapping is intentionally NOT changed here.
     *
     * Exact integer tokens match only that integer (not .5). Half tokens match
     * only their availability token. 4BHK+ means bhk_count >= 4 / options 4+.
     * Multiple plan tokens remain OR.
     *
     * @param  list<string>  $bhkFilters
     */
    protected function applyBhkFilters(Builder $query, array $bhkFilters): void
    {
        $query->where(function (Builder $outer) use ($bhkFilters): void {
            foreach ($bhkFilters as $bhkFilter) {
                $bhkFilter = (string) $bhkFilter;
                if ($bhkFilter === '') {
                    continue;
                }

                $spec = $this->bhkMatchSpec($bhkFilter);
                if ($spec === null) {
                    continue;
                }

                $outer->orWhere(function (Builder $builder) use ($spec): void {
                    foreach ($spec['options'] as $option) {
                        $builder->orWhereJsonContains('availability_options', $option);
                    }

                    if ($spec['count_min'] !== null) {
                        $builder->orWhere('bhk_count', '>=', $spec['count_min']);
                    }

                    if ($spec['count_exact'] !== null) {
                        $count = $spec['count_exact'];
                        $excludeOptions = $spec['exclude_options'];
                        $builder->orWhere(function (Builder $legacy) use ($count, $excludeOptions): void {
                            $legacy->where('bhk_count', $count);
                            foreach ($excludeOptions as $exclude) {
                                $legacy->where(function (Builder $notHalf) use ($exclude): void {
                                    $notHalf->whereNull('availability_options')
                                        ->orWhereJsonDoesntContain('availability_options', $exclude);
                                });
                            }
                        });
                    }
                });
            }
        });
    }

    /**
     * @return array{
     *     options: list<string>,
     *     count_exact: ?int,
     *     count_min: ?int,
     *     exclude_options: list<string>
     * }|null
     */
    protected function bhkMatchSpec(string $token): ?array
    {
        return match ($token) {
            '1RK' => [
                'options' => ['1 Room', '1 Room & Kitchen', 'Studio Room', '1RK'],
                'count_exact' => 0,
                'count_min' => null,
                'exclude_options' => [],
            ],
            '1BHK' => [
                'options' => ['1BHK'],
                'count_exact' => 1,
                'count_min' => null,
                'exclude_options' => ['1.5BHK'],
            ],
            '1.5BHK' => [
                'options' => ['1.5BHK'],
                'count_exact' => null,
                'count_min' => null,
                'exclude_options' => [],
            ],
            '2BHK' => [
                // Room aliases are exact-2 inventory labels, not .5 variants.
                'options' => ['2BHK', '2 Room', '2 Room & Kitchen'],
                'count_exact' => 2,
                'count_min' => null,
                'exclude_options' => ['2.5BHK'],
            ],
            '2.5BHK' => [
                'options' => ['2.5BHK'],
                'count_exact' => null,
                'count_min' => null,
                'exclude_options' => [],
            ],
            '3BHK' => [
                'options' => ['3BHK'],
                'count_exact' => 3,
                'count_min' => null,
                'exclude_options' => ['3.5BHK'],
            ],
            '3.5BHK' => [
                'options' => ['3.5BHK'],
                'count_exact' => null,
                'count_min' => null,
                'exclude_options' => [],
            ],
            '4BHK' => [
                'options' => ['4BHK'],
                'count_exact' => 4,
                'count_min' => null,
                'exclude_options' => ['4.5BHK', '4BHK+'],
            ],
            '4BHK+' => [
                'options' => ['4BHK', '4BHK+', '5BHK', '6BHK', 'Above 6BHK'],
                'count_exact' => null,
                'count_min' => 4,
                'exclude_options' => [],
            ],
            '5BHK+' => [
                'options' => ['5BHK', '6BHK', 'Above 6BHK'],
                'count_exact' => null,
                'count_min' => 5,
                'exclude_options' => [],
            ],
            'Office' => [
                'options' => ['Office'],
                'count_exact' => null,
                'count_min' => null,
                'exclude_options' => [],
            ],
            'Shop' => [
                'options' => ['Shop', 'Showroom'],
                'count_exact' => null,
                'count_min' => null,
                'exclude_options' => [],
            ],
            'PG' => [
                'options' => ['PG'],
                'count_exact' => null,
                'count_min' => null,
                'exclude_options' => [],
            ],
            default => null,
        };
    }

    /**
     * Supported sorts with stable id DESC/ASC tie-breaker (deterministic pages).
     * Default: id DESC (stable newest-first; never RAND).
     *
     * @param  array<string, mixed>  $plan
     */
    protected function applySort(Builder $query, array $plan): void
    {
        $sort = $plan['sort'] ?? null;
        if ($sort === 'price_low') {
            $query->orderBy('price', 'ASC')->orderByDesc('id');

            return;
        }
        if ($sort === 'price_high') {
            $query->orderBy('price', 'DESC')->orderByDesc('id');

            return;
        }
        if ($sort === 'most_viewed') {
            $query->orderByDesc('total_click')->orderByDesc('id');

            return;
        }
        if ($sort === 'most_liked') {
            $query->withCount('favourite')->orderByDesc('favourite_count')->orderByDesc('id');

            return;
        }

        // Ask AI default: stable newest-first (avoid RAND used by public list).
        $query->orderByDesc('id');
    }
}
