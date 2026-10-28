<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GA4's per-page conversion count, stored beside views at the same grain.
     *
     * Why it lives here rather than in a `daily_clicks` table: a 2026-08-29
     * scan of all 109 dataset tables found no per-car breakdown of click type.
     * `ga4_Events` carries `click` / `Call` / `select_item` but is site-wide —
     * one row per event per day, with no car identifier — so a table with a
     * `click_type` column could only ever hold one value. `keyEvents` is the
     * only click-shaped signal that resolves to a single car, and it arrives in
     * `ga4_PagesAndScreens`, the table daily views already come from.
     *
     * Nullable rather than defaulted to 0: rows synced before this column
     * existed genuinely have no measurement, and reading that as "zero
     * conversions" would invent a conversion problem that was never observed.
     */
    public function up(): void
    {
        Schema::table('daily_views', function (Blueprint $table) {
            $table->unsignedInteger('key_events')->nullable()->after('views');
        });
    }

    public function down(): void
    {
        Schema::table('daily_views', function (Blueprint $table) {
            $table->dropColumn('key_events');
        });
    }
};
