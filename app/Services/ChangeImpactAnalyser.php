<?php

namespace App\Services;

use App\Models\ChangeEvent;
use App\Models\DailyStatus;
use App\Models\DailyView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out what happened to a car's views, conversions and occupancy either
 * side of a change, and whether that beat the rest of its category.
 *
 * Nothing here is stored. Every figure is computed from the raw daily tables
 * at the window the page asks for, so changing the window never needs a
 * re-pull and never invalidates history — the same principle the daily_views
 * and daily_status tables were built on.
 *
 * The category comparison is the load-bearing part. A car's views rising after
 * a rank change proves nothing on its own if every car in the category rose;
 * the control group is what separates a real effect from a seasonal tide.
 */
class ChangeImpactAnalyser
{
    /**
     * How far apart the car and its category must be before the row is called
     * a win or a loss rather than noise.
     *
     * Views and conversions are compared as percentage growth, occupancy as
     * percentage points. Adjust here — these are the only two numbers that
     * decide the colours on /changes.
     */
    public const WIN_THRESHOLD = 10.0;

    public const LOSS_THRESHOLD = -10.0;

    /**
     * Conversions are sparse: measured over 2026-07-29..08-26, 213 of 358 cars
     * recorded zero and only 7.3% of car-days were non-zero. Below this many
     * conversions in the "before" window, a growth percentage is arithmetic
     * noise (0 -> 1 is not "+100% conversion"), so the page shows counts and
     * marks the comparison inconclusive instead.
     */
    public const MIN_CONVERSIONS_FOR_GROWTH = 3;

    /**
     * Attach before/after metrics and category comparisons to each event.
     *
     * @param  Collection<int, ChangeEvent>  $events
     * @param  int  $window  days either side of the change
     */
    public function analyse(Collection $events, int $window): Collection
    {
        if ($events->isEmpty()) {
            return $events;
        }

        // The after-window is only closed once the DATA covers it, not merely
        // once the calendar has passed it. GA4 lands roughly two days behind,
        // so judging completeness by wall-clock would read "no rows yet" as a
        // 100% collapse in views for every car at once.
        $dataThrough = $this->dataAvailableThrough();

        // car_serial -> om_serial, needed because occupancy is keyed by the OM
        // serial while everything else is keyed by the website id.
        $omByCar = $this->omSerialMap();

        // category -> [car_serial, ...], the control-group membership.
        $carsByCategory = $this->carsByCategory();

        // One set of window aggregates per distinct detection time, rather
        // than per event: 500 events typically share a handful of syncs.
        $windows = [];

        foreach ($events->pluck('detected_at')->unique() as $detectedAt) {
            $at = CarbonImmutable::parse($detectedAt);
            $key = $at->toDateTimeString();

            $beforeFrom = $at->subDays($window)->toDateString();
            $beforeTo = $at->subDay()->toDateString();
            $afterFrom = $at->toDateString();
            $afterTo = $at->addDays($window)->toDateString();

            $windows[$key] = [
                'complete' => $dataThrough !== null
                    && $at->addDays($window)->startOfDay()->lte($dataThrough),
                'after_ready_on' => $at->addDays($window),
                'before' => [
                    'views' => DailyView::metricsBetween($beforeFrom, $beforeTo),
                    'status' => DailyStatus::summariseBetween($beforeFrom, $beforeTo),
                ],
                'after' => [
                    'views' => DailyView::metricsBetween($afterFrom, $afterTo),
                    'status' => DailyStatus::summariseBetween($afterFrom, $afterTo),
                ],
            ];
        }

        return $events->map(function (ChangeEvent $event) use ($windows, $omByCar, $carsByCategory) {
            $w = $windows[CarbonImmutable::parse($event->detected_at)->toDateTimeString()];

            $event->window_complete = $w['complete'];
            $event->after_ready_on = $w['after_ready_on'];

            $car = $this->metricsFor($event->car_serial, $omByCar, $w);

            // Peers: the other cars in the same category. A price change has no
            // category, so the pool is every car that shares any category with
            // it — the closest honest control group available.
            $peers = $this->peersFor($event, $carsByCategory);

            $peer = $this->aggregatePeers($peers, $omByCar, $w);

            $event->before = $car['before'];
            $event->after = $car['after'];
            $event->peer_count = count($peers);

            foreach (['views', 'conversions', 'occupancy'] as $metric) {
                $carGrowth = $this->growth($metric, $car['before'][$metric], $car['after'][$metric]);
                $peerGrowth = $this->growth($metric, $peer['before'][$metric], $peer['after'][$metric]);

                $event->{$metric.'_growth'} = $carGrowth;
                $event->{$metric.'_peer_growth'} = $peerGrowth;

                $diff = ($carGrowth === null || $peerGrowth === null)
                    ? null
                    : round($carGrowth - $peerGrowth, 1);

                $event->{$metric.'_diff'} = $diff;
                $event->{$metric.'_verdict'} = $this->verdict($diff, $w['complete']);
            }

            return $event;
        });
    }

    /**
     * Growth for one metric.
     *
     * Views and conversions are relative (percent change). Occupancy is already
     * a percentage, so it moves in percentage points — expressing a jump from
     * 40% to 60% as "+50% growth" would invite reading it as 90%.
     */
    private function growth(string $metric, ?float $before, ?float $after): ?float
    {
        if ($before === null || $after === null) {
            return null;
        }

        if ($metric === 'occupancy') {
            return round($after - $before, 1);
        }

        if ($metric === 'conversions' && $before < self::MIN_CONVERSIONS_FOR_GROWTH) {
            // Too small a base for a percentage to mean anything.
            return null;
        }

        if ($before <= 0) {
            return null;
        }

        return round(($after - $before) / $before * 100, 1);
    }

    private function verdict(?float $diff, bool $complete): string
    {
        if (! $complete) {
            return 'pending';
        }

        if ($diff === null) {
            return 'unknown';
        }

        return match (true) {
            $diff >= self::WIN_THRESHOLD => 'win',
            $diff <= self::LOSS_THRESHOLD => 'loss',
            default => 'flat',
        };
    }

    /**
     * @return array{before: array<string, ?float>, after: array<string, ?float>}
     */
    private function metricsFor(string $carSerial, array $omByCar, array $w): array
    {
        $om = $omByCar[$carSerial] ?? null;
        $out = [];

        foreach (['before', 'after'] as $side) {
            $v = $w[$side]['views'][$carSerial] ?? null;
            $s = $om === null ? null : ($w[$side]['status'][$om] ?? null);

            $out[$side] = [
                'views' => $v === null ? null : (float) $v->views,
                // NULL (not 0) when the column was never measured for this car.
                'conversions' => $v === null || $v->key_events === null ? null : (float) $v->key_events,
                'occupancy' => $s === null ? null : ($s->occupancy_pct === null ? null : (float) $s->occupancy_pct),
            ];
        }

        return $out;
    }

    /**
     * The control group's combined movement.
     *
     * Views and conversions are summed across peers, occupancy is averaged —
     * summing occupancy percentages would be meaningless.
     *
     * @param  list<string>  $peers
     */
    private function aggregatePeers(array $peers, array $omByCar, array $w): array
    {
        $out = [];

        foreach (['before', 'after'] as $side) {
            $views = 0.0;
            $conversions = 0.0;
            $conversionsSeen = false;
            $occSum = 0.0;
            $occN = 0;

            foreach ($peers as $peerSerial) {
                $v = $w[$side]['views'][$peerSerial] ?? null;

                if ($v !== null) {
                    $views += (float) $v->views;

                    if ($v->key_events !== null) {
                        $conversions += (float) $v->key_events;
                        $conversionsSeen = true;
                    }
                }

                $om = $omByCar[$peerSerial] ?? null;
                $s = $om === null ? null : ($w[$side]['status'][$om] ?? null);

                if ($s !== null && $s->occupancy_pct !== null) {
                    $occSum += (float) $s->occupancy_pct;
                    $occN++;
                }
            }

            $out[$side] = [
                'views' => $peers === [] ? null : $views,
                'conversions' => $conversionsSeen ? $conversions : null,
                'occupancy' => $occN > 0 ? round($occSum / $occN, 2) : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function peersFor(ChangeEvent $event, array $carsByCategory): array
    {
        if ($event->category !== null) {
            $pool = $carsByCategory[$event->category] ?? [];
        } else {
            // Price change: no category on the row, so pool every car sharing
            // any category with this one.
            $pool = [];

            foreach ($carsByCategory as $cars) {
                if (in_array($event->car_serial, $cars, true)) {
                    $pool = array_merge($pool, $cars);
                }
            }

            $pool = array_values(array_unique($pool));
        }

        // The car itself is never part of its own control group.
        return array_values(array_filter($pool, fn ($s) => $s !== $event->car_serial));
    }

    /**
     * The last day for which we actually hold measurements.
     *
     * Views and fleet status arrive on different lags, so the earlier of the
     * two governs: a window is only comparable when both metrics cover it.
     */
    private function dataAvailableThrough(): ?CarbonImmutable
    {
        $views = DB::table('daily_views')->max('view_date');
        $status = DB::table('daily_status')->max('status_date');

        $dates = array_filter([$views, $status]);

        if ($dates === []) {
            return null;
        }

        return CarbonImmutable::parse(min($dates))->startOfDay();
    }

    /**
     * @return array<string, string>
     */
    private function omSerialMap(): array
    {
        return DB::table('occupancy_snapshots')
            ->select('car_serial', 'om_serial')
            ->whereNotNull('om_serial')
            ->distinct()
            ->pluck('om_serial', 'car_serial')
            ->all();
    }

    /**
     * @return array<string, list<string>>
     */
    private function carsByCategory(): array
    {
        $map = [];

        $rows = DB::table('occupancy_snapshots')
            ->select('car_serial', 'category')
            ->whereNotNull('category')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            $map[$row->category][] = $row->car_serial;
        }

        return array_map(fn ($c) => array_values(array_unique($c)), $map);
    }
}
