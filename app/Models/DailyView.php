<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per car per day of raw GA4 page views.
 *
 * Always aggregate from here on demand; never store a rolled-up total.
 */
class DailyView extends Model
{
    protected $table = 'daily_views';

    protected $fillable = ['car_serial', 'slug', 'view_date', 'views'];

    protected function casts(): array
    {
        return [
            'view_date' => 'date',
            'views' => 'integer',
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
}
