<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('putts', function (Blueprint $table) {
            // Nullable for good: the simple dial does not classify, and every putt
            // logged before this feature existed never will.
            $table->string('miss_cause')->nullable()->after('result');

            $table->index(['miss_cause', 'putter']);
        });
    }

    public function down(): void
    {
        Schema::table('putts', function (Blueprint $table) {
            $table->dropIndex(['miss_cause', 'putter']);
            $table->dropColumn('miss_cause');
        });
    }
};
