<?php

namespace App\Console\Commands;

use App\Models\DailyStatus;
use App\Models\DailyView;
use App\Models\OccupancySnapshot;
use App\Models\RefreshLog;
use App\Services\ChangeDetector;
use App\Services\OccupancyDataPuller;
use App\Services\PowerBiException;
use App\Services\PowerBiService;
use App\Services\WebsiteApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Triggers a Power BI dataset refresh, waits for it, pulls the occupancy and
 * views figures, and appends them to occupancy_snapshots as a dated snapshot.
 *
 * Snapshots are never overwritten — the history is the whole point.
 */
class PowerBiSync extends Command
{
    protected $signature = 'powerbi:sync
                            {--skip-refresh : Pull the current data without triggering a new refresh}
                            {--dry-run : Pull and summarise, but write nothing to the database}';

    protected $description = 'Refresh the Power BI dataset and store an occupancy/views snapshot';

    public function handle(): int
    {
        $startedAt = CarbonImmutable::now();

        try {
            $pbi = PowerBiService::fromConfig();
        } catch (PowerBiException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // The log row is created up front so an interrupted run still leaves a trace.
        $log = $dryRun ? null : RefreshLog::create([
            'started_at' => $startedAt,
            'status' => RefreshLog::STATUS_PENDING,
        ]);

        try {
            if ($this->option('skip-refresh')) {
                $this->warn('Skipping refresh — reading whatever the dataset currently holds.');
            } else {
                $this->refreshDataset($pbi);
            }

            $this->info('Pulling catalogue + ranks (live site) and occupancy + views (Power BI)…');

            // Rank and the catalogue come from the live website API; Power BI
            // supplies occupancy, plate, ownership and GA4 views.
            $result = (new OccupancyDataPuller($pbi, WebsiteApiClient::fromConfig()))->pull();
            $rows = $result['rows'];
            $dailyViews = $result['daily_views'];
            $dailyStatus = $result['daily_status'];
            $warnings = $result['warnings'];

            foreach ($warnings as $warning) {
                $this->warn('  ! '.$warning);
            }

            if ($rows === []) {
                throw new PowerBiException('The pull returned no rows; nothing was stored.');
            }

            $snapshotAt = CarbonImmutable::now();

            if ($dryRun) {
                $this->warn('Dry run — not writing to the database.');
            } else {
                $this->store($rows, $snapshotAt, $log);
                $this->storeDailyViews($dailyViews);
                $this->storeDailyStatus($dailyStatus);

                // Detection runs inside every sync so a rank or price move is
                // never missed for want of a manual step. It only reads
                // snapshots, so a failure here must not lose the pull.
                $warnings = array_merge($warnings, $this->detectChanges($snapshotAt));

                $log->update([
                    'completed_at' => CarbonImmutable::now(),
                    'status' => RefreshLog::STATUS_COMPLETED,
                    'rows_pulled' => count($rows),
                    // Warnings are kept even on a successful sync so a
                    // rank/position disagreement is never lost.
                    'warnings' => $warnings ? implode(PHP_EOL, $warnings) : null,
                ]);
            }

            $this->summarise($rows, $dailyViews, $dailyStatus, $snapshotAt, $startedAt);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Sync failed: '.$e->getMessage());

            Log::error('powerbi:sync failed', ['exception' => $e]);

            $log?->update([
                'completed_at' => CarbonImmutable::now(),
                'status' => RefreshLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Compare the snapshot just written against the previous one and record
     * any rank or price changes.
     *
     * Wrapped so a detection fault can never fail a sync that already stored
     * its data successfully — the snapshot is the irreplaceable part, and
     * detection can always be replayed later with the changes:detect --all command.
     *
     * @return list<string>
     */
    private function detectChanges(CarbonImmutable $snapshotAt): array
    {
        try {
            $result = (new ChangeDetector)->detectFor($snapshotAt->toDateTimeString());

            $this->info(sprintf(
                'Change detection: %d rank, %d price (%d rows compared).',
                $result['rank'],
                $result['price'],
                $result['compared']
            ));

            foreach ($result['warnings'] as $warning) {
                $this->warn('  ! '.$warning);
            }

            return $result['warnings'];
        } catch (Throwable $e) {
            $this->error('Change detection failed (the snapshot was still stored): '.$e->getMessage());
            Log::error('change detection failed', ['exception' => $e]);

            return ['Change detection failed: '.$e->getMessage()];
        }
    }

    /**
     * Ask Power BI to refresh, then poll until it settles.
     */
    private function refreshDataset(PowerBiService $pbi): void
    {
        $this->info('Requesting a dataset refresh…');

        $interval = (int) config('powerbi.poll_interval');
        $timeout = (int) config('powerbi.poll_timeout');

        $this->line(sprintf(
            '  Polling every %ds, giving up after %d minutes.',
            $interval,
            (int) round($timeout / 60)
        ));

        // Captures a baseline before triggering, so a previous run's result
        // can never be mistaken for ours.
        $pbi->refreshAndWait(function (string $status, int $elapsed) {
            $this->line(sprintf('  [%4ds] status: %s', $elapsed, $status));
        });

        $this->info('Refresh completed.');
    }

    /**
     * Append the snapshot rows. Every row in one run shares a single
     * snapshot_datetime so a refresh is one coherent point in time.
     */
    private function store(array $rows, CarbonImmutable $snapshotAt, RefreshLog $log): void
    {
        $payload = array_map(fn (array $row) => $row + [
            'snapshot_datetime' => $snapshotAt,
            'refresh_log_id' => $log->id,
        ], $rows);

        DB::transaction(function () use ($payload) {
            foreach (array_chunk($payload, 500) as $chunk) {
                OccupancySnapshot::insert($chunk);
            }
        });
    }

    /**
     * Upsert raw daily views. GA4 restates recent days, so re-syncing a day
     * must correct the existing row rather than add a duplicate.
     */
    private function storeDailyViews(array $dailyViews): void
    {
        if ($dailyViews === []) {
            return;
        }

        $now = CarbonImmutable::now();

        $payload = array_map(fn (array $row) => $row + [
            'created_at' => $now,
            'updated_at' => $now,
        ], $dailyViews);

        foreach (array_chunk($payload, 500) as $chunk) {
            DailyView::upsert($chunk, ['car_serial', 'view_date'], ['views', 'key_events', 'slug', 'updated_at']);
        }
    }

    /**
     * Upsert per-car-per-day status. Recent days get restated as contracts
     * close, so re-syncing a day must correct it rather than duplicate it.
     */
    private function storeDailyStatus(array $dailyStatus): void
    {
        if ($dailyStatus === []) {
            return;
        }

        $now = CarbonImmutable::now();

        $payload = array_map(fn (array $row) => $row + [
            'created_at' => $now,
            'updated_at' => $now,
        ], $dailyStatus);

        foreach (array_chunk($payload, 500) as $chunk) {
            DailyStatus::upsert(
                $chunk,
                ['car_serial', 'status_date'],
                ['rented', 'maintenance', 'parked', 'other', 'day_price', 'updated_at']
            );
        }
    }

    private function summarise(array $rows, array $dailyViews, array $dailyStatus, CarbonImmutable $snapshotAt, CarbonImmutable $startedAt): void
    {
        $cars = collect($rows)->pluck('car_serial')->unique();
        $categories = collect($rows)->pluck('category')->filter()->unique();

        $withOccupancy = collect($rows)->whereNotNull('occupancy_pct')->pluck('car_serial')->unique()->count();
        $withRank = collect($rows)->whereNotNull('rank_position')->count();

        $byCar = collect($rows)->unique('car_serial');
        $owned = $byCar->where('ownership', 'faster')->count();
        $lent = $byCar->where('ownership', 'lent')->count();
        $unknownOwner = $byCar->whereNull('ownership')->count();
        $withPlate = $byCar->whereNotNull('plate')->count();

        $viewRows = collect($dailyViews);
        $viewCars = $viewRows->pluck('car_serial')->unique()->count();
        $viewDays = $viewRows->pluck('view_date')->unique();

        $this->newLine();
        $this->info('Snapshot summary');
        $this->table(['Metric', 'Value'], [
            ['Snapshot time', $snapshotAt->toDateTimeString()],
            ['Rows stored (car x category)', count($rows)],
            ['Distinct cars', $cars->count()],
            ['Distinct categories', $categories->count()],
            ['Cars with an occupancy value', $withOccupancy.' / '.$cars->count()],
            ['Cars with a plate', $withPlate.' / '.$cars->count()],
            ['Ownership (faster / lent / unknown)', $owned.' / '.$lent.' / '.$unknownOwner],
            ['Daily status rows upserted', count($dailyStatus)],
            ['Daily view rows upserted', $viewRows->count()],
            ['Cars with view data', $viewCars.' / '.$cars->count()],
            ['Days of view data covered', $viewDays->count()
                ? $viewDays->count().' ('.$viewDays->min().' to '.$viewDays->max().')'
                : '0'],
            ['Rows with a published rank', $withRank.' / '.count($rows)],
            ['Elapsed', $startedAt->diffForHumans(CarbonImmutable::now(), true)],
        ]);

        if ($categories->isNotEmpty()) {
            $this->line('Categories: '.$categories->sort()->implode(', '));
        }
    }
}
