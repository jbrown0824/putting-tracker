<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many putts a drill gives you at each step, of which drill_makes_required
     * must be sunk: 2 attempts with 1 sunk is "two tries per rung".
     *
     * Existing drills needed every putt on a step to drop, which is attempts equal
     * to the makes required, so they are backfilled that way.
     */
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->unsignedSmallInteger('drill_attempts')->default(1)->after('drill_makes_required');
        });

        DB::table('challenges')->update(['drill_attempts' => DB::raw('drill_makes_required')]);
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropColumn('drill_attempts');
        });
    }
};
