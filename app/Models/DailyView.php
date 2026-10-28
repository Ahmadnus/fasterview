<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per car per day of raw GA4 page views and conversions.
 *
 * Always aggregate from here on demand; never store a rolled-up total.
 */
class DailyView extends Model
{
    protected $table = 'daily_views';

    protected $fillable = ['car_serial', 'slug', 'view_date', 'views', 'key_events'];

    protected function casts(): array
    {
        return [
            'view_date' => 'date',
            'views' => 'integer',
            'key_events' => 'integer',
        ];
    }

    /**
     * Total views per car across a date range, keyed by car_serial.
     *
     * @return \Illuminate\Support\Collection<string, int>
     */
    public static function totalsBetween(?string $from, ?string $to = null)
    {
        return static::query()
            ->when($from, fn (Builder $q) => $q->where('view_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('view_date', '<=', $to))
            ->groupBy('car_serial')
            ->selectRaw('car_serial, SUM(views) AS total')
            ->pluck('total', 'car_serial');
    }

    /**
     * Views and conversions together across a date range, keyed by car_serial.
     *
     * SUM() over an all-NULL group returns NULL, which is what we want: a car
     * whose days all predate the key_events column reports null rather than 0,
     * so "not measured" never reads as "no conversions".
     *
     * @return \Illuminate\Support\Collection<string, object>
     */
    public static function metricsBetween(?string $from, ?string $to = null)
    {
        return static::query()
            ->when($from, fn (Builder $q) => $q->where('view_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('view_date', '<=', $to))
            ->groupBy('car_serial')
            ->selectRaw('car_serial')
            ->selectRaw('SUM(views) AS views')
            ->selectRaw('SUM(key_events) AS key_events')
            ->get()
            ->keyBy('car_serial');
    }
}
