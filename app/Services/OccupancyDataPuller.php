<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Turns the Power BI model into flat snapshot rows.
 *
 * The dataset keeps the pieces we need in four separate tables, joined through
 * a bridge, so rather than one fragile DAX join we pull each table and stitch
 * them together in PHP:
 *
 *   Website API ID        FALLBACK ONLY — stale copy of the car catalogue
 *   RankPublished API     FALLBACK ONLY — stale copy of the ranks
 *   Website link with OM  bridge: Site ID <-> Matched CarSerial <-> Slug
 *   Car Occupancy         occupancy keyed by the OM CarSerial
 *   car serial - model    plate number, keyed by the OM CarSerial
 *   Car link with accounts ownership: an Asset account means we own the car
 *   Daily Status          per-car per-day rented/maintenance/parked flags
 *   ga4_PagesAndScreens   daily page views keyed by slug
 *
 * Two outputs, at two different grains:
 *
 *   snapshots   ONE ROW PER CAR PER CATEGORY, because a car carries a different
 *               rank in each category it appears in.
 *   dailyViews  ONE ROW PER CAR PER DAY, straight from GA4 and never rolled up.
 *               Totals over a window are computed at read time from these.
 *   dailyStatus ONE ROW PER CAR PER DAY of fleet status, same principle. This
 *               is what lets us compute occupancy for a chosen date range.
 *
 * The car catalogue and RANK come from the LIVE website API, not Power BI —
 * see WebsiteApiClient for why. Power BI still supplies occupancy, plate,
 * ownership and GA4 views, which the live API does not carry.
 */
class OccupancyDataPuller
{
    /** @var list<string> Non-fatal problems worth surfacing to the operator. */
    private array $warnings = [];

    public function __construct(
        private readonly PowerBiService $pbi,
        private readonly ?WebsiteApiClient $website = null,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, daily_views: list<array<string, mixed>>, daily_status: list<array<string, mixed>>, warnings: list<string>}
     */
    public function pull(): array
    {
        $this->warnings = [];

        [$cars, $ranks] = $this->fetchCatalogue();
        $serialByCarId = $this->fetchBridge();
        $occupancyBySerial = $this->fetchOccupancy();
        $plateBySerial = $this->fetchPlates();
        $ownedSerials = $this->fetchOwnedSerials();

        // Daily views need slug -> car mapping, so resolve it from the car list.
        $carIdBySlug = [];
        foreach ($cars as $car) {
            if ($car['slug'] !== null && $car['slug'] !== '') {
                $carIdBySlug[$this->normalise($car['slug'])] = $car['id'];
            }
        }

        $dailyViews = $this->fetchDailyViews($carIdBySlug);
        $dailyStatus = $this->fetchDailyStatus();

        $rows = [];

        foreach ($cars as $car) {
            $carId = $car['id'];

            $serial = $serialByCarId[$carId] ?? null;
            $occupancy = $serial !== null ? ($occupancyBySerial[$serial] ?? null) : null;
            $plate = $serial !== null ? ($plateBySerial[$serial] ?? null) : null;

            // Only classify ownership when we actually resolved the car in the
            // OM system. Without a serial we do not know — and "unknown" must
            // never be presented as "lent".
            $ownership = $serial === null
                ? null
                : (isset($ownedSerials[$serial]) ? 'faster' : 'lent');

            // A car with no categories still deserves a row, so it does not
            // silently vanish from the tracker.
            $categories = $car['categories'] ?: [null];

            foreach ($categories as $category) {
                $rankKey = $carId.'|'.$this->normalise((string) $category);


                $rows[] = [
                    'car_serial' => $carId,
                    'om_serial' => $serial,
                    'car_name' => $car['title'],
                    'plate' => $plate,
                    'ownership' => $ownership,
                    'category' => $category,
                    'occupancy_pct' => $occupancy,
                    'rank_position' => $ranks[$rankKey]['rank'] ?? null,
                    'price' => $car['price'],
                ];
            }
        }

        if ($rows === []) {
            $this->warnings[] = 'The pull produced no rows at all — check that the dataset tables are populated.';
        }

        return [
            'rows' => $rows,
            'daily_views' => $dailyViews,
            'daily_status' => $dailyStatus,
            'warnings' => $this->warnings,
        ];
    }

    // -----------------------------------------------------------------
    // Individual table pulls
    // -----------------------------------------------------------------

    /**
     * Car catalogue + ranks, preferring the live website API.
     *
     * Power BI's copies of both go stale between dataset refreshes, so the live
     * site is the source of truth. If it cannot be reached we fall back to
     * Power BI so the daily snapshot still happens — but that is recorded as a
     * warning, because the ranks it returns are known to drift.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, array{rank: int}>}
     */
    private function fetchCatalogue(): array
    {
        if ($this->website !== null) {
            try {
                $liveCars = $this->website->fetchCars();

                $cars = [];
                $ranks = [];

                foreach ($liveCars as $car) {
                    $cars[] = [
                        'id' => $car['id'],
                        'title' => $car['title'],
                        'slug' => $car['slug'],
                        'price' => $car['price'],
                        'categories' => $car['categories'],
                    ];

                    foreach ($car['ranks'] as $categoryKey => $rank) {
                        $ranks[$car['id'].'|'.$categoryKey] = ['rank' => $rank];
                    }
                }

                return [$cars, $ranks];
            } catch (PowerBiException $e) {
                if (! config('website.fallback_to_powerbi', true)) {
                    throw $e;
                }

                $this->warnings[] = 'LIVE WEBSITE API UNAVAILABLE ('.$e->getMessage()
                    .') — fell back to Power BI, so RANKS IN THIS SNAPSHOT MAY BE STALE.';
            }
        }

        return [$this->fetchWebsiteCars(), $this->fetchRanks()];
    }

    /**
     * Fallback catalogue from Power BI. Stale between dataset refreshes.
     *
     * @return list<array{id: string, title: ?string, slug: ?string, price: ?float, categories: list<string>}>
     */
    private function fetchWebsiteCars(): array
    {
        $dax = <<<'DAX'
        EVALUATE
        SELECTCOLUMNS(
            'Website API ID',
            "id", [id],
            "title", [title],
            "slug", [slug],
            "price", [price],
            "sale_price", [sale_price],
            "categories", [Categories_Text]
        )
        DAX;

        $cars = [];

        foreach ($this->pbi->executeDax($dax) as $row) {
            $id = $this->val($row, 'id');

            if ($id === null || $id === '') {
                continue;
            }

            $cars[] = [
                'id' => $id,
                'title' => $this->val($row, 'title'),
                'slug' => $this->val($row, 'slug'),
                'price' => $this->resolvePrice($row),
                'categories' => $this->parseCategories($this->val($row, 'categories'), $id),
            ];
        }

        return $cars;
    }

    /**
     * Fallback ranks from Power BI. Stale between dataset refreshes.
     *
     * Latest published rank per car per category.
     *
     * @return array<string, array{rank: int}>
     */
    private function fetchRanks(): array
    {
        $dax = <<<'DAX'
        EVALUATE
        SELECTCOLUMNS(
            'RankPublished API',
            "car_id", [car_id],
            "surface", [surface],
            "rank", [rank],
            "position", [position],
            "published_at", [published_at]
        )
        DAX;

        $rows = $this->pbi->executeDax($dax);

        // Newest publication first, so the first row we meet for a key wins.
        usort($rows, fn ($a, $b) => strcmp(
            (string) $this->val($b, 'published_at'),
            (string) $this->val($a, 'published_at')
        ));

        $ranks = [];
        $mismatches = 0;

        foreach ($rows as $row) {
            $carId = $this->val($row, 'car_id');
            $surface = $this->val($row, 'surface');
            $rank = $this->val($row, 'rank');

            if ($carId === null || $surface === null || $rank === null) {
                continue;
            }

            $position = $this->val($row, 'position');

            if ($position !== null && (int) $position !== (int) $rank) {
                $mismatches++;
            }

            $key = $carId.'|'.$this->normalise($surface);

            // Only keep the newest entry for each car/category pair.
            $ranks[$key] ??= ['rank' => (int) $rank];
        }

        if ($mismatches > 0) {
            // We store [rank]; if [position] ever diverges we want to know.
            $this->warnings[] = "RankPublished API: [rank] and [position] disagreed on {$mismatches} row(s). Stored [rank].";
        }

        return $ranks;
    }

    /**
     * Website Site ID => OM CarSerial.
     *
     * @return array<string, string>
     */
    private function fetchBridge(): array
    {
        $dax = <<<'DAX'
        EVALUATE
        SELECTCOLUMNS(
            'Website link with OM',
            "site_id", [Site ID],
            "car_serial", [Matched CarSerial],
            "status", [Status]
        )
        DAX;

        $map = [];
        $unmatched = 0;

        foreach ($this->pbi->executeDax($dax) as $row) {
            $siteId = $this->val($row, 'site_id');
            $serial = $this->val($row, 'car_serial');

            if ($siteId === null) {
                continue;
            }

            if ($serial === null || $serial === '') {
                $unmatched++;

                continue;
            }

            $map[$siteId] = $serial;
        }

        if ($unmatched > 0) {
            $this->warnings[] = "Bridge table: {$unmatched} website car(s) have no Matched CarSerial — those rows get no occupancy.";
        }

        return $map;
    }

    /**
     * OM CarSerial => occupancy percentage (0-100).
     *
     * @return array<string, float>
     */
    private function fetchOccupancy(): array
    {
        $dax = <<<'DAX'
        EVALUATE
        SELECTCOLUMNS(
            'Car Occupancy',
            "car_serial", [CarSerial],
            "occupancy", [Occupancy %]
        )
        DAX;

        $map = [];

        foreach ($this->pbi->executeDax($dax) as $row) {
            $serial = $this->val($row, 'car_serial');
            $value = $this->val($row, 'occupancy');

            if ($serial === null || $value === null || $value === '') {
                continue;
            }

            // Power BI stores this as a fraction (0.9154 = 91.54%).
            $map[$serial] = round(min((float) $value * 100, 100), 2);
        }

        return $map;
    }

    /**
     * Raw GA4 daily page views, mapped onto website cars.
     *
     * Returns one row per car per day exactly as GA4 reports it. Nothing is
     * summed here — the UI decides the window and aggregates at read time.
     *
     * @param  array<string, string>  $carIdBySlug  normalised slug => website car id
     * @return list<array{car_serial: string, slug: string, view_date: string, views: int}>
     */
    private function fetchDailyViews(array $carIdBySlug): array
    {
        // How far back to (re)pull daily rows on each sync. This bounds the
        // query only; it is NOT an aggregation window.
        $days = max(1, (int) config('powerbi.views_lookback_days', 30));
        $from = CarbonImmutable::now()->subDays($days)->startOfDay();

        $dax = sprintf(<<<'DAX'
        EVALUATE
        SUMMARIZE(
            FILTER(
                'ga4_PagesAndScreens',
                'ga4_PagesAndScreens'[_DATA_DATE] >= DATE(%d, %d, %d)
            ),
            'ga4_PagesAndScreens'[slug],
            'ga4_PagesAndScreens'[_DATA_DATE],
            "views", SUM('ga4_PagesAndScreens'[screenPageViews])
        )
        DAX, $from->year, $from->month, $from->day);

        // One GA4 slug can appear more than once per day (different content
        // groups), so accumulate per car per day rather than overwriting.
        $totals = [];
        $unmapped = [];

        foreach ($this->pbi->executeDax($dax) as $row) {
            $slug = $this->val($row, 'slug');
            $date = $this->val($row, '_DATA_DATE');

            if ($slug === null || $slug === '' || $date === null) {
                continue;
            }

            $carId = $carIdBySlug[$this->normalise($slug)] ?? null;

            if ($carId === null) {
                // GA4 tracks pages that are not cars (blog, landing pages).
                $unmapped[$slug] = true;

                continue;
            }

            $day = CarbonImmutable::parse($date)->toDateString();
            $key = $carId.'|'.$day;

            if (! isset($totals[$key])) {
                $totals[$key] = [
                    'car_serial' => $carId,
                    'slug' => $slug,
                    'view_date' => $day,
                    'views' => 0,
                ];
            }

            $totals[$key]['views'] += (int) $this->val($row, 'views');
        }

        if ($unmapped !== []) {
            $this->warnings[] = sprintf(
                'GA4: %d slug(s) did not match a website car and were skipped (e.g. %s).',
                count($unmapped),
                implode(', ', array_slice(array_keys($unmapped), 0, 3))
            );
        }

        return array_values($totals);
    }

    /**
     * OM CarSerial => plate number.
     *
     * @return array<string, string>
     */
    private function fetchPlates(): array
    {
        $dax = <<<'DAX'
        EVALUATE
        SELECTCOLUMNS(
            'car serial - model',
            "car_serial", [Car serial],
            "plate", [Plate]
        )
        DAX;

        $map = [];

        foreach ($this->pbi->executeDax($dax) as $row) {
            $serial = $this->val($row, 'car_serial');
            $plate = $this->val($row, 'plate');

            if ($serial === null || $plate === null || trim($plate) === '') {
                continue;
            }

            $map[$serial] = trim($plate);
        }

        return $map;
    }

    /**
     * Set of OM CarSerials that Faster owns.
     *
     * A car we own carries an "Asset" (and matching "Depreciation") account in
     * the books; a car we take on lease/contract only carries Income and
     * Expences accounts. So the presence of an Asset account is the ownership
     * signal, and it joins cleanly on CarSerial.
     *
     * @return array<string, true>
     */
    private function fetchOwnedSerials(): array
    {
        $dax = <<<'DAX'
        EVALUATE
        SUMMARIZE(
            FILTER(
                'Car link with accounts',
                'Car link with accounts'[Account Type] = "Asset"
            ),
            'Car link with accounts'[CarSerial]
        )
        DAX;

        $owned = [];

        foreach ($this->pbi->executeDax($dax) as $row) {
            $serial = $this->val($row, 'CarSerial');

            if ($serial !== null && $serial !== '') {
                $owned[$serial] = true;
            }
        }

        if ($owned === []) {
            $this->warnings[] = 'No cars came back with an Asset account — ownership could not be determined.';
        }

        return $owned;
    }

    /**
     * Raw per-car per-day fleet status, keyed by OM CarSerial.
     *
     * Kept at day grain so occupancy can be computed for any window the UI
     * picks, rather than relying on Power BI's single undated Occupancy %.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchDailyStatus(): array
    {
        $days = max(1, (int) config('powerbi.status_lookback_days', 120));
        $from = CarbonImmutable::now()->subDays($days)->startOfDay();
        $to = CarbonImmutable::now()->endOfDay();

        $dax = sprintf(<<<'DAX'
        EVALUATE
        SELECTCOLUMNS(
            FILTER(
                'Daily Status',
                'Daily Status'[Date] >= DATE(%d, %d, %d)
                    && 'Daily Status'[Date] <= DATE(%d, %d, %d)
            ),
            "car_serial", [CarSerial],
            "status_date", [Date],
            "rent", [Rent_Flag],
            "maint", [Maintenance_Flag],
            "park", [Parked_Flag],
            "other", [Other_Flag],
            "day_price", [DayPrice]
        )
        DAX,
            $from->year, $from->month, $from->day,
            $to->year, $to->month, $to->day
        );

        $out = [];

        foreach ($this->pbi->executeDax($dax) as $row) {
            $serial = $this->val($row, 'car_serial');
            $date = $this->val($row, 'status_date');

            if ($serial === null || $serial === '' || $date === null) {
                continue;
            }

            $key = $serial.'|'.CarbonImmutable::parse($date)->toDateString();

            // Guard against the source emitting a car/day twice.
            $out[$key] = [
                'car_serial' => $serial,
                'status_date' => CarbonImmutable::parse($date)->toDateString(),
                'rented' => (int) (float) $this->val($row, 'rent'),
                'maintenance' => (int) (float) $this->val($row, 'maint'),
                'parked' => (int) (float) $this->val($row, 'park'),
                'other' => (int) (float) $this->val($row, 'other'),
                'day_price' => round((float) $this->val($row, 'day_price'), 2),
            ];
        }

        if ($out === []) {
            $this->warnings[] = 'Daily Status returned no rows — range occupancy will be unavailable.';
        }

        return array_values($out);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The website exposes the live rate in sale_price and often leaves price at 0.
     */
    private function resolvePrice(array $row): ?float
    {
        foreach (['sale_price', 'price'] as $field) {
            $value = $this->val($row, $field);

            if ($value !== null && (float) $value > 0) {
                return round((float) $value, 2);
            }
        }

        return null;
    }

    /**
     * Categories_Text is a JSON array of {id, title, pivot} objects.
     *
     * @return list<string>
     */
    private function parseCategories(?string $json, string $carId): array
    {
        if (blank($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $this->warnings[] = "Could not parse Categories_Text for car {$carId}.";

            return [];
        }

        $titles = [];

        foreach ($decoded as $entry) {
            $title = is_array($entry) ? Arr::get($entry, 'title') : null;

            if (is_string($title) && $title !== '') {
                $titles[$this->normalise($title)] = trim($title);
            }
        }

        return array_values($titles);
    }

    /**
     * Power BI returns keys as "Table[Column]" or "[Alias]"; read them by bare name.
     */
    private function val(array $row, string $name): ?string
    {
        foreach ($row as $key => $value) {
            $bare = trim((string) preg_replace('/^.*\[|\]$/', '', $key));

            if (strcasecmp($bare, $name) === 0) {
                if ($value === null || is_array($value)) {
                    return null;
                }

                return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return null;
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
