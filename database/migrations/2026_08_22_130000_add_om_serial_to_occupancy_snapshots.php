<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The OM CarSerial behind each website car.
     *
     * `car_serial` holds the website Site ID, but `daily_status` (and the
     * plate/ownership sources) are keyed by the OM CarSerial. Storing the
     * resolved link lets the UI join the two without re-querying Power BI.
     */
    public function up(): void
    {
        Schema::table('occupancy_snapshots', function (Blueprint $table) {
            $table->string('om_serial', 20)->nullable()->after('car_serial');
            $table->index('om_serial', 'idx_om_serial');
        });
    }

    public function down(): void
    {
        Schema::table('occupancy_snapshots', function (Blueprint $table) {
            $table->dropIndex('idx_om_serial');
            $table->dropColumn('om_serial');
        });
    }
};
