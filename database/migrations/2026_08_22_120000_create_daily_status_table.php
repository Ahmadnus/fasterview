<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw per-car per-day fleet status from Power BI's `Daily Status`.
     *
     * Same principle as `daily_views`: stored at native day grain, never
     * pre-aggregated. Occupancy for "the last 7 / 30 / 90 days" is computed at
     * read time as rented_days / days_on_fleet over whatever range the UI asks
     * for — which also sidesteps the unknown window baked into Power BI's
     * `Car Occupancy[Occupancy %]`.
     *
     * Keyed by OM CarSerial (not the website id), matching the source.
     */
    public function up(): void
    {
        Schema::create('daily_status', function (Blueprint $table) {
            $table->id();
            $table->string('car_serial', 20);
            $table->date('status_date');

            // Mutually exclusive flags describing what the car did that day.
            $table->boolean('rented')->default(false);
            $table->boolean('maintenance')->default(false);
            $table->boolean('parked')->default(false);
            $table->boolean('other')->default(false);

            // Rate earned that day (0 when not rented).
            $table->decimal('day_price', 10, 2)->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['car_serial', 'status_date'], 'uniq_car_status_day');
            $table->index(['status_date'], 'idx_status_date');
            $table->index(['car_serial', 'status_date'], 'idx_car_status_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_status');
    }
};
