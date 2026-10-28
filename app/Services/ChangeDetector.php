<?php

namespace App\Services;

use App\Models\ChangeEvent;
use Illuminate\Support\Facades\DB;

/**
 * Compares one snapshot against the one before it and records every rank or
 * price change it finds.
 *
 * This reads only `occupancy_snapshots`; it never re-pulls. That keeps it
 * cheap enough to run at the end of every sync and safe to replay over
 * historical snapshots without touching the Power BI or website APIs.
 */
class ChangeDetector
{
    /**
     * Rank changes are only rejected when the source is *known* to have been
     * stale — that is, when the sync that produced the snapshot recorded the
     * live-API fallback warning. In that state the ranks come from Power BI,
     * which was measured on 2026-08-22 to disagree with the live site on 825
     * of 1090 rows; writing those would be pure noise.
     *
     * Volume alone is deliberately NOT a rejection criterion. A 2026-08-28 run
     * with a perfectly healthy live API showed 492 of 1075 ranks (46%) moving
     * across an 8-day gap. Those were real, and a ratio-based guard would have
     * thrown all of them away. Ratio now only raises a warning to look at.
     */
    public const RANK_STALE_MARKER = 'RANKS IN THIS SNAPSHOT MAY BE STALE';

    /** Above this share of ranks moving, the run is worth a human glance. */
    public const NOTEWORTHY_RANK_CHANGE_RATIO = 0.35;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Detect changes introduced by the snapshot at $snapshotAt, comparing it
     * against the most recent snapshot before it.
     *
     * @return array{inserted:int, rank:int, price:int, compared:int, warnings:list<string>, skipped:bool}
     */
    public function detectFor(string $snapshotAt, bool $force = false, bool $dryRun = false): array
    {
        $this->warnings = [];

        $previousAt = $this->previousSnapshotBefore($snapshotAt);

        if ($previousAt === null) {
            $this->warnings[] = 'No earlier snapshot exists, so there is nothing to compare against yet.';

            return $this->result([], null, 0, true);
        }

        $current = $this->rowsAt($snapshotAt);
        $previous = $this->rowsAt($previousAt);

        $events = array_merge(
            $this->rankChanges($previous, $current, $snapshotAt, $previousAt, $force),
            $this->priceChanges($previous, $current, $snapshotAt, $previousAt),
        );

        $compared = count(array_intersect_key($current['by_key'], $previous['by_key']));

        // Cars that appeared or disappeared are a different kind of event and
        // are deliberately not logged here, but they are worth surfacing.
        $this->noteFleetMovement($previous, $current);

        if ($this->rankAborted) {
            return $this->result([], $previousAt, $compared, true);
        }

        if (! $dryRun && $events !== []) {
            foreach (array_chunk($events, 500) as $chunk) {
                ChangeEvent::insert($chunk);
            }
        }

        return $this->result($events, $previousAt, $compared, false);
    }

    private bool $rankAborted = false;

    /**
     * Detect changes for every snapshot in history that has a predecessor.
     * Used by the backfill path so the page has something to show on day one.
     *
     * @return array{steps:int, inserted:int, warnings:list<string>}
     */
    public function detectAll(bool $force = false, bool $dryRun = false): array
    {
        $times = DB::table('occupancy_snapshots')
            ->select('snapshot_datetime')
            ->distinct()
            ->orderBy('snapshot_datetime')
            ->pluck('snapshot_datetime');

        $inserted = 0;
        $steps = 0;
        $warnings = [];

        // The first snapshot has no predecessor, so start at the second.
        foreach ($times->skip(1) as $time) {
            $result = $this->detectFor((string) $time, $force, $dryRun);
            $inserted += $result['inserted'];
            $steps++;

            foreach ($result['warnings'] as $warning) {
                $warnings[] = substr((string) $time, 0, 19).': '.$warning;
            }
        }

        return ['steps' => $steps, 'inserted' => $inserted, 'warnings' => $warnings];
    }

    /**
     * Rank is per car per category, so the comparison key carries both.
     *
     * @return list<array<string, mixed>>
     */
    private function rankChanges(array $previous, array $current, string $at, string $prevAt, bool $force): array
    {
        $this->rankAborted = false;

        $events = [];
        $nullTransitions = 0;
        $comparable = 0;

        foreach ($current['by_key'] as $key => $row) {
            $before = $previous['by_key'][$key] ?? null;

            // Present in only one snapshot: a new or removed car/category.
            if ($before === null) {
                continue;
            }

            $old = $before->rank_position;
            $new = $row->rank_position;

            // A rank appearing or vanishing almost always means the source was
            // incomplete, not that the car left the ranking. Counted and
            // reported rather than written as a change.
            if ($old === null || $new === null) {
                if ($old !== $new) {
                    $nullTransitions++;
                }

                continue;
            }

            $comparable++;

            if ((int) $old === (int) $new) {
                continue;
            }

            $events[] = [
                'car_serial' => $row->car_serial,
                'car_name' => $row->car_name,
                'category' => $row->category,
                'change_type' => ChangeEvent::TYPE_RANK,
                'old_value' => (float) $old,
                'new_value' => (float) $new,
                'detected_at' => $at,
                'previous_at' => $prevAt,
            ];
        }

        if ($nullTransitions > 0) {
            $this->warnings[] = sprintf(
                'Rank: %d row(s) gained or lost a rank value entirely; not logged as changes '
                .'(usually an incomplete source rather than a real ranking decision).',
                $nullTransitions
            );
        }

        $ratio = $comparable > 0 ? count($events) / $comparable : 0.0;

        // Reject only on proven staleness, never on volume alone.
        if ($this->sourceWasStale($at) && ! $force) {
            $this->rankAborted = true;

            $this->warnings[] = sprintf(
                'ABORTED: the sync behind this snapshot fell back to Power BI, so its ranks are '
                .'known-stale. %d apparent rank change(s) discarded rather than written as real '
                .'decisions. Re-run with --force to record them anyway.',
                count($events)
            );

            return [];
        }

        if ($ratio > self::NOTEWORTHY_RANK_CHANGE_RATIO) {
            $this->warnings[] = sprintf(
                'NOTE: %d of %d comparable ranks changed (%.0f%%). Recorded as real, because the '
                .'source was healthy — but a share this large usually means a long gap between '
                .'syncs rather than a single big reshuffle. Check the gap before reading too much '
                .'into any one row.',
                count($events), $comparable, $ratio * 100
            );
        }

        return $events;
    }

    /**
     * Price is a car-level attribute that repeats across the car's category
     * rows, so it is compared once per car and stored with a NULL category.
     *
     * @return list<array<string, mixed>>
     */
    private function priceChanges(array $previous, array $current, string $at, string $prevAt): array
    {
        $events = [];
        $nullTransitions = 0;

        foreach ($current['by_car'] as $serial => $row) {
            $before = $previous['by_car'][$serial] ?? null;

            if ($before === null) {
                continue;
            }

            $old = $before->price;
            $new = $row->price;

            if ($old === null || $new === null) {
                if ($old !== $new) {
                    $nullTransitions++;
                }

                continue;
            }

            // decimal columns arrive as strings; compare numerically, and only
            // past the stored precision so 250.00 vs 250 is not a "change".
            if (abs((float) $old - (float) $new) < 0.005) {
                continue;
            }

            $events[] = [
                'car_serial' => $row->car_serial,
                'car_name' => $row->car_name,
                'category' => null,
                'change_type' => ChangeEvent::TYPE_PRICE,
                'old_value' => (float) $old,
                'new_value' => (float) $new,
                'detected_at' => $at,
                'previous_at' => $prevAt,
            ];
        }

        if ($nullTransitions > 0) {
            $this->warnings[] = sprintf(
                'Price: %d car(s) gained or lost a price value entirely; not logged as changes.',
                $nullTransitions
            );
        }

        return $events;
    }

    private function noteFleetMovement(array $previous, array $current): void
    {
        $added = count(array_diff_key($current['by_car'], $previous['by_car']));
        $removed = count(array_diff_key($previous['by_car'], $current['by_car']));

        if ($added > 0 || $removed > 0) {
            $this->warnings[] = sprintf(
                'Fleet moved: %d car(s) appeared, %d disappeared. Not logged as changes by design.',
                $added, $removed
            );
        }
    }

    /**
     * Did the sync that produced this snapshot record the stale-rank warning?
     *
     * The snapshot rows carry the refresh_log they came from, and the sync
     * persists its warnings there even on success, so staleness is a recorded
     * fact rather than something to infer from the numbers.
     */
    private function sourceWasStale(string $at): bool
    {
        $warnings = DB::table('occupancy_snapshots as s')
            ->join('refresh_log as r', 'r.id', '=', 's.refresh_log_id')
            ->where('s.snapshot_datetime', $at)
            ->value('r.warnings');

        return $warnings !== null
            && str_contains((string) $warnings, self::RANK_STALE_MARKER);
    }

    /**
     * Load one snapshot, indexed both ways the comparison needs.
     *
     * @return array{by_key: array<string, object>, by_car: array<string, object>}
     */
    private function rowsAt(string $at): array
    {
        $rows = DB::table('occupancy_snapshots')
            ->select('car_serial', 'car_name', 'category', 'rank_position', 'price')
            ->where('snapshot_datetime', $at)
            ->get();

        $byKey = [];
        $byCar = [];

        foreach ($rows as $row) {
            $byKey[$row->car_serial.'|'.($row->category ?? '')] = $row;
            // Any one row per car carries that car's price.
            $byCar[$row->car_serial] ??= $row;
        }

        return ['by_key' => $byKey, 'by_car' => $byCar];
    }

    private function previousSnapshotBefore(string $at): ?string
    {
        $value = DB::table('occupancy_snapshots')
            ->where('snapshot_datetime', '<', $at)
            ->max('snapshot_datetime');

        return $value === null ? null : (string) $value;
    }

    private function result(array $events, ?string $previousAt, int $compared, bool $skipped): array
    {
        $byType = array_count_values(array_column($events, 'change_type'));

        return [
            'inserted' => count($events),
            'rank' => $byType[ChangeEvent::TYPE_RANK] ?? 0,
            'price' => $byType[ChangeEvent::TYPE_PRICE] ?? 0,
            'compared' => $compared,
            'previous_at' => $previousAt,
            'warnings' => $this->warnings,
            'skipped' => $skipped,
        ];
    }
}
