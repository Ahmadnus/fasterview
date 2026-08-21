<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail: one row per `powerbi:sync` run.
     */
    public function up(): void
    {
        Schema::create('refresh_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            // pending / completed / failed
            $table->string('status', 20)->default('pending');

            $table->integer('rows_pulled')->nullable();
            $table->text('error_message')->nullable();

            // Non-fatal problems from an otherwise successful sync, e.g. a
            // rank/position disagreement in RankPublished API.
            $table->text('warnings')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_log');
    }
};
