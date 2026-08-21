<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plate number (for searching) and who owns the car.
     *
     * `ownership` is 'faster' when the car has an Asset account in Power BI's
     * `Car link with accounts` — you only carry an asset/depreciation account
     * for a vehicle you own — and 'lent' when it has accounts but no Asset one.
     * It stays NULL when the car has no matched OM CarSerial at all, because
     * "we could not determine it" must not be shown as "lent".
     */
    public function up(): void
    {
        Schema::table('occupancy_snapshots', function (Blueprint $table) {
            $table->string('plate', 32)->nullable()->after('car_name');
            $table->string('ownership', 10)->nullable()->after('plate');

            $table->index('plate', 'idx_plate');
            $table->index('ownership', 'idx_ownership');
        });
    }

    public function down(): void
    {
        Schema::table('occupancy_snapshots', function (Blueprint $table) {
            $table->dropIndex('idx_plate');
            $table->dropIndex('idx_ownership');
            $table->dropColumn(['plate', 'ownership']);
        });
    }
};
