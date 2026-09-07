<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('putts', function (Blueprint $table) {
            // Nullable for good. A putt logged with neither a position nor the old
            // slope-and-break pair has no position to recover, and inventing one
            // would poison the analysis this column exists to support.
            $table->string('clock_position')->nullable()->after('context');

            // Every position query groups by distance, the same way the putter
            // queries do.
            $table->index(['clock_position', 'distance_ft']);
        });
    }

    /**
     * break_direction is deliberately NOT dropped here.
     *
     * It looked dead — zero rows locally — but production had 58 putts carrying it,
     * and together with slope it pins down the clock position exactly. Dropping it
     * in the same migration that adds the new column would have destroyed the only
     * copy of that data before anything could read it.
     *
     * Expand now, contract later: run `putts:backfill-positions` to move the data
     * across, verify it, and only then ship a migration that drops the column.
     */
    public function down(): void
    {
        Schema::table('putts', function (Blueprint $table) {
            $table->dropIndex(['clock_position', 'distance_ft']);
            $table->dropColumn('clock_position');
        });
    }
};
