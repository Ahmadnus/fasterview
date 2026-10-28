<?php

namespace App\Console\Commands;

use App\Models\ChangeEvent;
use App\Services\ChangeDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Detect rank and price changes between stored snapshots.
 *
 * Runs automatically at the end of `powerbi:sync`; this command exists so the
 * same logic can be replayed over history or inspected on its own.
 */
class ChangesDetect extends Command
{
    protected $signature = 'changes:detect
                            {--all : Replay detection across every snapshot in history}
                            {--force : Write rank changes even when the volume looks like a stale source}
                            {--dry-run : Report what would be detected without writing}
                            {--fresh : Delete existing change_events first (use with --all)}';

    protected $description = 'Compare snapshots and log every rank or price change';

    public function handle(): int
    {
        $detector = new ChangeDetector;
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('fresh')) {
            if ($dryRun) {
                $this->warn('Dry run — not clearing change_events.');
            } else {
                DB::table('change_events')->delete();
                $this->warn('Cleared change_events.');
            }
        }

        if ($this->option('all')) {
            $this->info('Replaying detection across all snapshots…');

            $result = $detector->detectAll((bool) $this->option('force'), $dryRun);

            foreach ($result['warnings'] as $warning) {
                $this->warn('  ! '.$warning);
            }

            $this->info(sprintf(
                '%d snapshot step(s) compared, %d change(s) %s.',
                $result['steps'],
                $result['inserted'],
                $dryRun ? 'found' : 'recorded'
            ));
        } else {
            $latest = DB::table('occupancy_snapshots')->max('snapshot_datetime');

            if ($latest === null) {
                $this->error('No snapshots exist yet. Run powerbi:sync first.');

                return self::FAILURE;
            }

            $result = $detector->detectFor((string) $latest, (bool) $this->option('force'), $dryRun);

            $this->reportOne((string) $latest, $result, $dryRun);
        }

        $this->showSample();

        return self::SUCCESS;
    }

    private function reportOne(string $latest, array $result, bool $dryRun): void
    {
        $this->info('Comparing the latest snapshot against its predecessor.');

        $this->table(['Metric', 'Value'], [
            ['Latest snapshot', $latest],
            ['Compared against', $result['previous_at'] ?? '—'],
            ['Car/category rows in both', $result['compared']],
            ['Rank changes', $result['rank']],
            ['Price changes', $result['price']],
            ['Written', $dryRun ? 'no (dry run)' : ($result['skipped'] ? 'no (skipped)' : 'yes')],
        ]);

        foreach ($result['warnings'] as $warning) {
            $this->warn('  ! '.$warning);
        }
    }

    /**
     * Show what actually landed, so the numbers above can be sanity-checked
     * against real cars rather than taken on trust.
     */
    private function showSample(): void
    {
        $total = ChangeEvent::count();

        $this->newLine();
        $this->info("change_events now holds {$total} row(s).");

        if ($total === 0) {
            return;
        }

        $byType = ChangeEvent::query()
            ->selectRaw('change_type, COUNT(*) AS n, MIN(detected_at) AS first_seen, MAX(detected_at) AS last_seen')
            ->groupBy('change_type')
            ->get();

        $this->table(
            ['Type', 'Rows', 'First', 'Last'],
            $byType->map(fn ($r) => [$r->change_type, $r->n, $r->first_seen, $r->last_seen])->all()
        );

        $sample = ChangeEvent::query()->orderByDesc('detected_at')->orderBy('car_serial')->limit(25)->get();

        $this->newLine();
        $this->info('Most recent changes (up to 25):');
        $this->table(
            ['Car', 'Name', 'Category', 'Type', 'Old', 'New', 'Move', 'Detected'],
            $sample->map(fn (ChangeEvent $e) => [
                $e->car_serial,
                mb_strimwidth((string) $e->car_name, 0, 28, '…'),
                $e->category ?? '—',
                $e->change_type,
                $this->fmt($e->old_value, $e->change_type),
                $this->fmt($e->new_value, $e->change_type),
                $this->move($e),
                $e->detected_at?->toDateTimeString(),
            ])->all()
        );
    }

    private function fmt(mixed $value, string $type): string
    {
        if ($value === null) {
            return '—';
        }

        return $type === ChangeEvent::TYPE_RANK
            ? (string) (int) $value
            : number_format((float) $value, 2);
    }

    private function move(ChangeEvent $e): string
    {
        $improved = $e->improved();

        if ($improved === null) {
            return '—';
        }

        $delta = abs((float) $e->new_value - (float) $e->old_value);

        return ($improved ? '▲ ' : '▼ ').($e->change_type === ChangeEvent::TYPE_RANK
            ? (string) (int) $delta
            : number_format($delta, 2));
    }
}
