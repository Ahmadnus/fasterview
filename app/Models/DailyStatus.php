<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per car per day: was it rented, in maintenance, or parked.
 *
 * Aggregate on read; never store a rolled-up total.
 */
class DailyStatus extends Model
{
    protected $table = 'daily_status';

    protected $fillable = [
        'car_serial', 'status_date', 'rented', 'maintenance', 'parked', 'other', 'day_price',
    ];

    protected function casts(): array
    {
        return [
            'status_date' => 'date',
            'rented' => 'boolean',
            'maintenance' => 'boolean',
            'parked' => 'boolean',
            'other' => 'boolean',
            'day_price' => 'decimal:2',
        ];
    }

    /**
     * Per-car totals across a date range, keyed by OM car_serial.
     *
     * Occupancy is rented days over days the car was actually on the fleet —
     * a car that only joined mid-range is not punished for days it did not exist.
     *
     * @return \Illuminate\Support\Collection<string, object>
     */
    public static function summariseBetween(string $from, string $to)
    {
        return static::query()
            ->whereBetween('status_date', [$from, $to])
            ->groupBy('car_serial')
            ->selectRaw('car_serial')
            ->selectRaw('COUNT(*) AS days_on_fleet')
            ->selectRaw('SUM(rented) AS rented_days')
            ->selectRaw('SUM(maintenance) AS maintenance_days')
            ->selectRaw('SUM(parked) AS parked_days')
            ->selectRaw('SUM(CASE WHEN rented = 1 THEN day_price ELSE 0 END) AS revenue')
            ->get()
            ->keyBy('car_serial')
            ->map(function ($row) {
                $row->occupancy_pct = $row->days_on_fleet > 0
                    ? round($row->rented_days / $row->days_on_fleet * 100, 2)
                    : null;

                return $row;
            });
    }
}
