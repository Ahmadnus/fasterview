<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per detected rank or price change.
     *
     * Detection compares the snapshot just written against the one before it,
     * so this table is a derived log — it holds no data that could not be
     * recomputed from `occupancy_snapshots`. That is deliberate: it makes the
     * detector safe to re-run and safe to backfill over existing history.
     *
     * Grain matches the source signal, which differs by type:
     *   - rank  — per car per category (a car ranks differently in each)
     *   - price — per car, `category` NULL (price is a car-level attribute,
     *             so recording it per category would multiply one real change
     *             into four identical rows for a car in four categories)
     *
     * `old_value` / `new_value` are decimal for both types so one pair of
     * columns serves rank (whole numbers) and price (fractional) alike.
     */
    public function up(): void
    {
        Schema::create('change_events', function (Blueprint $table) {
            $table->id();

            $table->string('car_serial', 20);          // website Site ID, as in occupancy_snapshots
            $table->string('car_name')->nullable();     // denormalised so the page needs no join
            $table->string('category', 100)->nullable(); // NULL for price changes
            $table->string('change_type', 20);           // 'rank' | 'price'

            $table->decimal('old_value', 10, 2)->nullable();
            $table->decimal('new_value', 10, 2)->nullable();

            // The snapshot that revealed the change, and the one it was
            // compared against. Keeping both makes the before/after windows
            // exact rather than inferred.
            $table->dateTime('detected_at');
            $table->dateTime('previous_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['car_serial'], 'idx_car');
            $table->index(['detected_at'], 'idx_detected');
            $table->index(['change_type', 'detected_at'], 'idx_type_detected');
            $table->index(['category'], 'idx_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_events');
    }
};
