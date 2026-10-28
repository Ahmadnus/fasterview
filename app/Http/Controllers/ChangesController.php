<?php

namespace App\Http\Controllers;

use App\Models\ChangeEvent;
use App\Models\OccupancySnapshot;
use App\Services\ChangeImpactAnalyser;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ChangesController extends Controller
{
    /** How far back to list changes. Mirrors the occupancy page. */
    private const RANGES = [7, 30, 90];

    /** Before/after comparison windows, in days. */
    private const WINDOWS = [7, 14, 30];

    public function index(Request $request)
    {
        $days = $this->whitelist($request->query('days'), self::RANGES, 30);
        $window = $this->whitelist($request->query('window'), self::WINDOWS, 7);

        $category = $request->query('category') ?: null;
        $type = in_array($request->query('type'), [ChangeEvent::TYPE_RANK, ChangeEvent::TYPE_PRICE], true)
            ? $request->query('type')
            : null;

        $from = CarbonImmutable::now()->subDays($days)->startOfDay();

        $events = ChangeEvent::query()
            ->where('detected_at', '>=', $from)
            // A price change is car-level and carries no category, so filtering
            // on a category must still surface price moves for the cars that
            // sit in it — otherwise picking any category silently hides every
            // price change, and "category + price only" returns nothing.
            ->when($category, fn ($q) => $q->where(function ($q) use ($category) {
                $q->where('category', $category)
                    ->orWhere(fn ($q) => $q->whereNull('category')
                        ->whereIn('car_serial', $this->carsInCategory($category)));
            }))
            ->when($type, fn ($q) => $q->where('change_type', $type))
            ->orderByDesc('detected_at')
            ->orderBy('car_name')
            ->get();

        $events = (new ChangeImpactAnalyser)->analyse($events, $window);

        // The newest change is always the least informative one — its after-
        // window has not closed yet — so sorting by date alone buries every row
        // that actually has a result. This lets the page jump straight to the
        // ones that can be judged. Completeness is only known after analysis,
        // so it is filtered here rather than in the query.
        // Defaults to results-only. The newest change is always the least
        // informative one — its after-window has not closed — so an unfiltered
        // landing page leads with rows that all read "in window" and looks
        // identical to having detected nothing at all.
        // 'all' is spelled out rather than left as an empty string: an empty
        // query value is indistinguishable from an absent one, which silently
        // re-applied the default instead of clearing the filter.
        $ready = $request->query('ready') ?: '1';

        if ($ready === '1') {
            $events = $events->filter(fn ($e) => $e->window_complete)->values();
        } elseif ($ready === 'pending') {
            $events = $events->filter(fn ($e) => ! $e->window_complete)->values();
        }

        return view('changes.index', [
            'events' => $events,
            'categories' => OccupancySnapshot::query()
                ->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'days' => $days,
            'window' => $window,
            'ranges' => self::RANGES,
            'windows' => self::WINDOWS,
            'category' => $category,
            'type' => $type,
            'ready' => $ready,
            'winThreshold' => ChangeImpactAnalyser::WIN_THRESHOLD,
            'lossThreshold' => ChangeImpactAnalyser::LOSS_THRESHOLD,
            'minConversions' => ChangeImpactAnalyser::MIN_CONVERSIONS_FOR_GROWTH,
        ]);
    }

    /**
     * Website ids of every car currently listed in a category.
     *
     * @return list<string>
     */
    private function carsInCategory(string $category): array
    {
        return OccupancySnapshot::query()
            ->where('category', $category)
            ->distinct()
            ->pluck('car_serial')
            ->all();
    }

    /**
     * @param  list<int>  $allowed
     */
    private function whitelist(mixed $value, array $allowed, int $default): int
    {
        $value = (int) $value;

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
