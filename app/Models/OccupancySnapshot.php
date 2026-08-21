<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupancySnapshot extends Model
{
    protected $table = 'occupancy_snapshots';

    // Snapshots are append-only; we set created_at via the DB default.
    public $timestamps = false;

    protected $fillable = [
        'car_serial', 'om_serial', 'car_name', 'plate', 'ownership', 'category', 'snapshot_datetime',
        'occupancy_pct', 'rank_position', 'price', 'refresh_log_id',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_datetime' => 'datetime',
            'created_at'        => 'datetime',
            'occupancy_pct'     => 'decimal:2',
            'price'             => 'decimal:2',
            'rank_position'     => 'integer',
        ];
    }

    public function refreshLog(): BelongsTo
    {
        return $this->belongsTo(RefreshLog::class, 'refresh_log_id');
    }
}
