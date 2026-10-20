<?php

declare(strict_types=1);

namespace App\Services\AskAi\Query;

use App\Models\Projects;
use App\Services\PublicProjectSearch;
use Illuminate\Support\Collection;

class AskAiProjectQuery
{
    public function __construct(
        protected PublicProjectSearch $projectSearch,
    ) {}

    /**
     * @param  array<string, mixed>  $route
     * @return Collection<int, Projects>
     */
    public function search(array $route): Collection
    {
        $limit = max(1, (int) config('ask_ai.public_query.result_limit', 8));
        $filters = [];
        if (! empty($route['location'])) {
            $filters['search'] = (string) $route['location'];
        }
        if (! empty($route['maxPrice'])) {
            $filters['max_budget'] = (int) $route['maxPrice'];
        }
        if (! empty($route['bhk'])) {
            $filters['configurations'] = [(int) $route['bhk'] . ' BHK'];
        }

        $query = Projects::query()->publiclyVisible();
        if ($filters !== []) {
            $errors = $this->projectSearch->validate($filters);
            if ($errors === []) {
                $this->projectSearch->apply($query, $filters);
            }
        }

        if (! empty($route['location'])) {
            $city = (string) $route['location'];
            $query->where(function ($q) use ($city): void {
                $q->where('city', 'LIKE', $city . '%')
                    ->orWhere('area', 'LIKE', '%' . $city . '%')
                    ->orWhere('title', 'LIKE', '%' . $city . '%');
            });
        }

        $place = ! empty($route['area'])
            ? (string) $route['area']
            : (! empty($route['address_query']) ? (string) $route['address_query'] : '');
        if ($place !== '') {
            $term = str_replace(['%', '_'], '', $place);
            if ($term !== '') {
                $query->where(function ($q) use ($term): void {
                    $q->where('area', 'LIKE', '%' . $term . '%')
                        ->orWhere('title', 'LIKE', '%' . $term . '%')
                        ->orWhere('city', 'LIKE', '%' . $term . '%');
                });
            }
        }

        return $query->orderByDesc('id')->limit($limit)->get();
    }
}
