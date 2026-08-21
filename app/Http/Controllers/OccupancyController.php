<?php

namespace App\Http\Controllers;

use App\Models\DailyStatus;
use App\Models\DailyView;
use App\Models\OccupancySnapshot;
use App\Models\RefreshLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class OccupancyController extends Controller
{
    /** Day ranges offered in the filter. */
    private const RANGES = [7, 30, 90];

    public function index(Request $request)
    {
        [$days, $from, $to] = $this->range($request);
        $category = $request->query('category') ?: null;
        $ownership = in_array($request->query('ownership'), ['faster', 'lent'], true)
            ? $request->query('ownership')
            : null;

        // Latest snapshot per car per category inside the chosen window.
        $latest = DB::table('occupancy_snapshots')
            ->select('car_serial', 'category', DB::raw('MAX(snapshot_datetime) AS md'))
            ->where('snapshot_datetime', '>=', $from)
            ->groupBy('car_serial', 'category');

        $rows = DB::table('occupancy_snapshots as s')
            ->joinSub($latest, 'l', function ($join) {
                $join->on('s.car_serial', '=', 'l.car_serial')
                    ->on('s.snapshot_datetime', '=', 'l.md')
                    // Null-safe compare: a car may have no category.
                    ->whereRaw('s.category <=> l.category');
            })
            ->when($category, fn ($q) => $q->where('s.category', $category))
            ->when($ownership, fn ($q) => $q->where('s.ownership', $ownership))
            ->select(
                's.car_serial', 's.car_name', 's.plate', 's.ownership', 's.category',
                's.occupancy_pct', 's.rank_position', 's.price', 's.snapshot_datetime'
            )
            ->get();

        // Views are summed on demand from the raw daily rows over the same
        // window — never read from a pre-aggregated column.
        $viewTotals = DailyView::totalsBetween($from->toDateString(), $to->toDateString());

        $rows = $rows->map(function ($row) use ($viewTotals) {
            $row->views = (int) ($viewTotals[$row->car_serial] ?? 0);

            return $row;
        })->sortByDesc('occupancy_pct')->values();

        return view('occupancy.index', [
            'rows' => $rows,
            'categories' => OccupancySnapshot::query()
                ->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'days' => $days,
            'ranges' => self::RANGES,
            'category' => $category,
            'ownership' => $ownership,
            'from' => $from,
            'to' => $to,
            'lastSync' => RefreshLog::latest('id')->first(),
        ]);
    }

    /**
     * Single car: occupancy history and daily views side by side.
     */
    public function show(Request $request, string $serial)
    {
        [$days, $from, $to] = $this->range($request);

        $snapshots = OccupancySnapshot::query()
            ->where('car_serial', $serial)
            ->where('snapshot_datetime', '>=', $from)
            ->orderBy('snapshot_datetime')
            ->get();

        abort_if($snapshots->isEmpty() && ! OccupancySnapshot::where('car_serial', $serial)->exists(), 404);

        // Fleet status for the chosen range, from raw per-day rows. This is
        // what the chart shows now: actual rented / parked / maintenance days,
        // rather than a flat line of snapshot readings.
        $omSerial = $snapshots->last()->om_serial ?? null;

        $status = $omSerial === null
            ? collect()
            : DailyStatus::query()
                ->where('car_serial', $omSerial)
                ->whereBetween('status_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('status_date')
                ->get();

        $rentedDays = $status->where('rented', true)->count();
        $maintDays = $status->where('maintenance', true)->count();
        $parkedDays = $status->where('parked', true)->count();
        $daysOnFleet = $status->count();

        // Consecutive rented days = one rental. Back-to-back contracts read as
        // one block, so treat this as "rental spells", not exact contract count.
        $spells = 0;
        $prevRented = false;
        foreach ($status as $day) {
            if ($day->rented && ! $prevRented) {
                $spells++;
            }
            $prevRented = (bool) $day->rented;
        }

        $views = DailyView::query()
            ->where('car_serial', $serial)
            ->whereBetween('view_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('view_date')
            ->get();

        return view('occupancy.show', [
            'serial' => $serial,
            'plate' => $snapshots->last()->plate ?? null,
            'ownership' => $snapshots->last()->ownership ?? null,
            'carName' => $snapshots->last()->car_name
                ?? OccupancySnapshot::where('car_serial', $serial)->value('car_name'),
            'categories' => $snapshots->pluck('category')->filter()->unique()->values(),
            'latest' => $snapshots->last(),
            'statusLabels' => $status->pluck('status_date')->map->toDateString(),
            'statusValues' => $status->map(fn ($d) => $d->rented ? 3 : ($d->maintenance ? 2 : ($d->parked ? 1 : 0))),
            'statusPrices' => $status->map(fn ($d) => (float) $d->day_price),
            'rentedDays' => $rentedDays,
            'maintDays' => $maintDays,
            'parkedDays' => $parkedDays,
            'daysOnFleet' => $daysOnFleet,
            'spells' => $spells,
            'rangeOccupancy' => $daysOnFleet > 0 ? round($rentedDays / $daysOnFleet * 100, 2) : null,
            'rangeRevenue' => $status->where('rented', true)->sum('day_price'),
            'omSerial' => $omSerial,
            'viewLabels' => $views->pluck('view_date')->map->toDateString(),
            'viewValues' => $views->pluck('views'),
            'viewsTotal' => $views->sum('views'),
            'days' => $days,
            'ranges' => self::RANGES,
        ]);
    }

    /**
     * "Pull Now" — runs the sync in-request for testing.
     *
     * Defaults to the fast path (no Power BI refresh, ~20s). A full refresh can
     * take many minutes, which is what the scheduler is for.
     */
    public function sync(Request $request)
    {
        $full = $request->boolean('full');

        // A full refresh polls Power BI for up to 15 minutes.
        set_time_limit($full ? (int) config('powerbi.poll_timeout') + 300 : 300);

        $exit = Artisan::call('powerbi:sync', $full ? [] : ['--skip-refresh' => true]);
        $output = trim(Artisan::output());

        return redirect()
            ->route('occupancy.index', $request->only('category', 'days', 'ownership'))
            ->with($exit === 0 ? 'status' : 'error', $exit === 0
                ? ($full ? 'Full refresh complete.' : 'Data pulled (no Power BI refresh triggered).')
                : 'Sync failed: '.mb_substr($output, -400));
    }

    /**
     * @return array{0: int, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $days = (int) $request->query('days', 30);

        if (! in_array($days, self::RANGES, true)) {
            $days = 30;
        }

        $to = CarbonImmutable::now();

        return [$days, $to->subDays($days)->startOfDay(), $to];
    }
}
