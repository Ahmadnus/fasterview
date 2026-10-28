<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a change event came from, because the two sources differ in how
     * precisely they date a change.
     *
     * 'snapshot'  — found by diffing two of our own snapshots. The change is
     *               known to have happened somewhere between them, so an 8-day
     *               gap between syncs dates it only to within 8 days.
     * 'rank_api'  — read from `RankPublished API`, which records every ranking
     *               publication with its own `published_at`, `prev_rank` and
     *               `delta_vs_prev`. Dated to the minute, and available back to
     *               2026-07-08.
     *
     * Keeping both lets the page prefer precisely-dated history while still
     * catching anything the ranking API never published — price moves in
     * particular, which it does not carry at all.
     */
    public function up(): void
    {
        Schema::table('change_events', function (Blueprint $table) {
            $table->string('source', 20)->default('snapshot')->after('change_type');
            $table->index(['source'], 'idx_source');
        });
    }

    public function down(): void
    {
        Schema::table('change_events', function (Blueprint $table) {
            $table->dropIndex('idx_source');
            $table->dropColumn('source');
        });
    }
};
