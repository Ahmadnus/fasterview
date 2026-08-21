<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the live fastercars.ae catalogue API.
 *
 * This is the authoritative source for RANK. Power BI's `RankPublished API`
 * table only updates when the dataset refreshes, so its ranks go stale — a
 * comparison on 2026-08-22 found 825 of 1090 car/category ranks disagreeing
 * with the live site, plus 68 ranks the dataset had left empty.
 *
 * The rank of a car within a category is `categories[].pivot.sort` — the same
 * ordering the website itself renders.
 */
class WebsiteApiClient
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeout = 60,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('website.cars_url'),
            (int) config('website.timeout', 60),
        );
    }

    /**
     * Fetch the catalogue, normalised to the same shape the puller uses.
     *
     * @return list<array{
     *     id: string, title: ?string, slug: ?string, price: ?float,
     *     categories: list<string>, ranks: array<string, int>
     * }>
     *
     * @throws PowerBiException on transport or shape problems.
     */
    public function fetchCars(): array
    {
        $response = Http::acceptJson()->timeout($this->timeout)->get($this->url);

        if ($response->failed()) {
            throw new PowerBiException(
                'Website API request failed (HTTP '.$response->status().') for '.$this->url
            );
        }

        $cars = $response->json('data.cars');

        if (! is_array($cars)) {
            throw new PowerBiException('Website API returned an unexpected shape: no data.cars array.');
        }

        $out = [];

        foreach ($cars as $car) {
            if (! is_array($car) || ! isset($car['id'])) {
                continue;
            }

            $categories = [];
            $ranks = [];

            foreach ((array) ($car['categories'] ?? []) as $category) {
                $title = is_array($category) ? Arr::get($category, 'title') : null;

                if (! is_string($title) || $title === '') {
                    continue;
                }

                $key = mb_strtolower(trim($title));
                $categories[$key] = trim($title);

                // pivot.sort is the live position of this car in this category.
                $sort = Arr::get($category, 'pivot.sort');

                if (is_numeric($sort)) {
                    $ranks[$key] = (int) $sort;
                }
            }

            $out[] = [
                'id' => (string) $car['id'],
                'title' => isset($car['title']) ? (string) $car['title'] : null,
                'slug' => isset($car['slug']) ? (string) $car['slug'] : null,
                'price' => $this->resolvePrice($car),
                'categories' => array_values($categories),
                'ranks' => $ranks,
            ];
        }

        if ($out === []) {
            throw new PowerBiException('Website API returned zero usable cars.');
        }

        Log::info('Website API catalogue fetched', ['cars' => count($out)]);

        return $out;
    }

    /**
     * The site shows sale_price as the live rate and often leaves price at 0.
     */
    private function resolvePrice(array $car): ?float
    {
        foreach (['sale_price', 'price'] as $field) {
            $value = $car[$field] ?? null;

            if (is_numeric($value) && (float) $value > 0) {
                return round((float) $value, 2);
            }
        }

        return null;
    }
}
