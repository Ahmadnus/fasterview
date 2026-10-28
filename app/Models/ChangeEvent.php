<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A detected rank or price change for one car.
 *
 * @see \App\Services\ChangeDetector
 */
class ChangeEvent extends Model
{
    protected $table = 'change_events';

    public const TYPE_RANK = 'rank';

    public const TYPE_PRICE = 'price';

    /** Found by diffing two of our snapshots; dated only to within that gap. */
    public const SOURCE_SNAPSHOT = 'snapshot';

    /** Read from RankPublished API with its own published_at; precisely dated. */
    public const SOURCE_RANK_API = 'rank_api';

    // created_at comes from the DB default; there is no updated_at — a
    // detected change is a fact about a moment and is never edited.
    public $timestamps = false;

    protected $fillable = [
        'car_serial', 'car_name', 'category', 'change_type', 'source',
        'old_value', 'new_value', 'detected_at', 'previous_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'previous_at' => 'datetime',
            'created_at' => 'datetime',
            'old_value' => 'decimal:2',
            'new_value' => 'decimal:2',
        ];
    }

    /**
     * A rank change to a smaller number is an improvement (rank 1 beats rank 9);
     * a price change is reported as-is. Used for display, not for scoring.
     */
    public function improved(): ?bool
    {
        if ($this->old_value === null || $this->new_value === null) {
            return null;
        }

        return $this->change_type === self::TYPE_RANK
            ? (float) $this->new_value < (float) $this->old_value
            : (float) $this->new_value > (float) $this->old_value;
    }
}
