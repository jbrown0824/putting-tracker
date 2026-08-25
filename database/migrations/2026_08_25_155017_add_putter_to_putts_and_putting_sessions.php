<?php

use App\Enums\Putter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('putting_sessions', function (Blueprint $table) {
            $table->string('putter')->default(Putter::Blade->value)->index()->after('context');
        });

        Schema::table('putts', function (Blueprint $table) {
            $table->string('putter')->default(Putter::Blade->value)->after('context');

            $table->index(['putter', 'distance_ft']);
            $table->index(['putter', 'context']);
        });
    }

    public function down(): void
    {
        Schema::table('putts', function (Blueprint $table) {
            $table->dropIndex(['putter', 'distance_ft']);
            $table->dropIndex(['putter', 'context']);
            $table->dropColumn('putter');
        });

        Schema::table('putting_sessions', function (Blueprint $table) {
            $table->dropColumn('putter');
        });
    }
};
