<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per car, per Power BI refresh. Rows are never updated in place —
     * every sync appends a new snapshot so occupancy/views history is preserved.
     */
    public function up(): void
    {
        Schema::create('occupancy_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('car_serial', 20);
            $table->string('car_name')->nullable();
            $table->string('category', 100)->nullable();

            // Actual moment the data was pulled, not just the calendar day.
            $table->dateTime('snapshot_datetime');

            $table->decimal('occupancy_pct', 5, 2)->nullable();
            $table->integer('rank_position')->nullable();

            // NOTE: there is deliberately no `views` column here. Views live in
            // `daily_views` at day grain and are summed on demand over whatever
            // range the UI asks for — never pre-aggregated into a snapshot.
            $table->decimal('price', 10, 2)->nullable();

            // Links a snapshot back to the sync run that produced it.
            $table->foreignId('refresh_log_id')->nullable()
                ->constrained('refresh_log')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['car_serial', 'snapshot_datetime'], 'idx_car_date');
            $table->index(['category', 'snapshot_datetime'], 'idx_category_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occupancy_snapshots');
    }
};
