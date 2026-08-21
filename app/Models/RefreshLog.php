<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RefreshLog extends Model
{
    protected $table = 'refresh_log';

    public $timestamps = false;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'started_at', 'completed_at', 'status', 'rows_pulled', 'error_message', 'warnings',
    ];

    protected function casts(): array
    {
        return [
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'created_at'   => 'datetime',
            'rows_pulled'  => 'integer',
        ];
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(OccupancySnapshot::class, 'refresh_log_id');
    }
}
