<?php

namespace App\Console\Commands;

use App\Models\ChangeEvent;
use App\Services\PowerBiException;
use App\Services\PowerBiService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill real rank-change history from `RankPublished API`.
 *
 * Diffing our own snapshots can only date a change to somewhere between two
 * syncs, and it only reaches back as far as the first snapshot. The ranking
 * API keeps every publication with its own `published_at`, `prev_rank` and
 * `delta_vs_prev` — roughly 6,000 real moves back to 2026-07-08 — so the
 * before/after windows can be computed against changes whose after-period has
 * already closed, instead of waiting a week for the first result.
 *
 * Price is deliberately not backfilled: this table carries no price.
 */
class ChangesBackfillRanks extends Command
{
    protected $signature = 'changes:backfill-ranks
                            {--dry-run : Report what would be written without writing}
                            {--fresh : Delete existing rank_api events first}
                            {--since= : Only publications on or after this date (Y-m-d)}';

    protected $description = 'Import dated rank-change history from RankPublished API';

    public const SOURCE = 'rank_api';

    /**
     * The ranking surfaces were renamed partway through; both eras appear in
     * the table. Mapped to the website category names the rest of the system
     * uses so a backfilled row filters identically to a detected one.
     */
    private const SURFACE_ALIASES = [
        'suv car' => 'Rent SUV',
        'luxury car' => 'Rent Luxury',
        'sports car' => 'Rent Sports',
        'monthly car' => 'Rent Monthly',
        'modified car' => 'Rent Modified',
        'convertible car' => 'Rent Convertible',
        'economy car' => 'Rent Economy Car',
        'super car rental' => 'Rent Supercars',
        'muscle car rental' => 'Rent Muscle Car',
        '7 seater car rental' => 'Rent 7 Seater',
    ];

    public function handle(): int
    {
        try {
            $pbi = PowerBiService::fromConfig();
        } catch (PowerBiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('fresh')) {
            $deleted = DB::table('change_events')->where('source', self::SOURCE)->delete();
            $this->warn("Cleared {$deleted} existing rank_api event(s).");
        }

        // Only categories the website actually uses; the ranking table also
        // carries internal surfaces (flash_sale, brand:7) that are not
        // categories at all and would filter to nothing on the page.
        $validCategories = DB::table('occupancy_snapshots')
            ->whereNotNull('category')->distinct()->pluck('category')
            ->mapWithKeys(fn ($c) => [mb_strtolower($c) => $c])->all();

        $carNames = DB::table('occupancy_snapshots')
            ->select('car_serial', 'car_name')->distinct()
            ->pluck('car_name', 'car_serial')->all();

        $this->info('Reading RankPublished API…');

        try {
            $rows = $pbi->executeDax($this->dax());
        } catch (PowerBiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(number_format(count($rows)).' published rank move(s) returned.');

        $events = [];
        $skippedSurface = [];
        $skippedCar = 0;
        $skippedSame = 0;

        foreach ($rows as $row) {
            $carId = (string) $this->val($row, 'car_id');
            $surface = (string) $this->val($row, 'surface');
            $publishedAt = $this->val($row, 'published_at');
            $prev = $this->val($row, 'prev_rank');
            $rank = $this->val($row, 'rank');

            if ($carId === '' || $publishedAt === null || $prev === null || $rank === null) {
                continue;
            }

            if ((int) $prev === (int) $rank) {
                $skippedSame++;

                continue;
            }

            // Unknown cars would produce rows the page can never join to views
            // or occupancy, so they are counted rather than stored.
            if (! isset($carNames[$carId])) {
                $skippedCar++;

                continue;
            }

            $category = $this->mapSurface($surface, $validCategories);

            if ($category === null) {
                $skippedSurface[$surface] = ($skippedSurface[$surface] ?? 0) + 1;

                continue;
            }

            $at = CarbonImmutable::parse($publishedAt);

            $events[] = [
                'car_serial' => $carId,
                'car_name' => $carNames[$carId],
                'category' => $category,
                'change_type' => ChangeEvent::TYPE_RANK,
                'source' => self::SOURCE,
                'old_value' => (float) $prev,
                'new_value' => (float) $rank,
                'detected_at' => $at->toDateTimeString(),
                'previous_at' => null,
            ];
        }

        $this->report($events, $skippedSurface, $skippedCar, $skippedSame);

        if ($events === []) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing written.');

            return self::SUCCESS;
        }

        foreach (array_chunk($events, 500) as $chunk) {
            ChangeEvent::insert($chunk);
        }

        $this->info('Wrote '.number_format(count($events)).' rank change(s).');

        return self::SUCCESS;
    }

    private function dax(): string
    {
        $since = $this->option('since');

        $filter = "NOT(ISBLANK('RankPublished API'[published_at]))
                && NOT(ISBLANK('RankPublished API'[prev_rank]))
                && 'RankPublished API'[prev_rank] <> 'RankPublished API'[rank]";

        if ($since) {
            $d = CarbonImmutable::parse($since);
            $filter .= sprintf(" && 'RankPublished API'[published_at] >= DATE(%d,%d,%d)", $d->year, $d->month, $d->day);
        }

        return <<<DAX
        EVALUATE
        SELECTCOLUMNS(
            FILTER('RankPublished API', {$filter}),
            "car_id", 'RankPublished API'[car_id],
            "surface", 'RankPublished API'[surface],
            "prev_rank", 'RankPublished API'[prev_rank],
            "rank", 'RankPublished API'[rank],
            "published_at", 'RankPublished API'[published_at]
        )
        DAX;
    }

    /**
     * @param  array<string, string>  $validCategories
     */
    private function mapSurface(string $surface, array $validCategories): ?string
    {
        $key = mb_strtolower(trim($surface));

        if (isset($validCategories[$key])) {
            return $validCategories[$key];
        }

        $alias = self::SURFACE_ALIASES[$key] ?? null;

        // An alias still has to name a category the website really uses.
        return $alias !== null && isset($validCategories[mb_strtolower($alias)])
            ? $validCategories[mb_strtolower($alias)]
            : null;
    }

    private function report(array $events, array $skippedSurface, int $skippedCar, int $skippedSame): void
    {
        $dates = array_column($events, 'detected_at');

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Rank changes mapped', number_format(count($events))],
            ['Distinct cars', count(array_unique(array_column($events, 'car_serial')))],
            ['Distinct categories', count(array_unique(array_column($events, 'category')))],
            ['Date range', $dates ? min($dates).'  to  '.max($dates) : '—'],
            ['Skipped: rank unchanged', number_format($skippedSame)],
            ['Skipped: car not in our data', $skippedCar],
            ['Skipped: non-category surface', array_sum($skippedSurface)],
        ]);

        if ($skippedSurface !== []) {
            arsort($skippedSurface);
            $this->warn('  ! Surfaces that are not website categories (ignored):');
            foreach (array_slice($skippedSurface, 0, 8, true) as $surface => $n) {
                $this->line(sprintf('      %-24s %d', $surface, $n));
            }
        }
    }

    private function val(array $row, string $wanted): mixed
    {
        foreach ($row as $key => $value) {
            if (strcasecmp((string) preg_replace('/^.*\[|\]$/', '', $key), $wanted) === 0) {
                return $value;
            }
        }

        return null;
    }
}
