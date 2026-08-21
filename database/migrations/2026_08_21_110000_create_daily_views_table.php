<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw GA4 page views at their native grain: one row per car per day.
     *
     * Nothing here is pre-aggregated. "Views over the last N days" is answered
     * by summing this table across a range chosen in the UI, so changing the
     * window never requires re-pulling or invalidates existing history.
     *
     * Rows are upserted on (car_serial, view_date): GA4 restates recent days,
     * so re-syncing a day must correct it rather than duplicate it.
     */
    public function up(): void
    {
        Schema::create('daily_views', function (Blueprint $table) {
            $table->id();
            $table->string('car_serial', 20);

            // Kept for traceability back to the GA4 row that produced this.
            $table->string('slug')->nullable();

            $table->date('view_date');
            $table->unsignedInteger('views')->default(0);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['car_serial', 'view_date'], 'uniq_car_day');
            $table->index(['view_date'], 'idx_view_date');
            $table->index(['car_serial', 'view_date'], 'idx_car_view_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_views');
    }
};
