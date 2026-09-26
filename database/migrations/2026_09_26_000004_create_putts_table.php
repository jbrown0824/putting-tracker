<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('putts', function (Blueprint $table) {
            $table->id();
            // Generated on the phone, so a replayed batch updates rather than duplicates.
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('putting_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('putter_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('distance_ft');
            $table->string('result');
            // Nullable for good: the simple dial does not classify line misses.
            $table->string('miss_cause')->nullable();
            $table->string('context');
            $table->string('surface_type')->nullable();
            $table->string('slope')->nullable();
            $table->string('clock_position')->nullable();
            // Set only on putts hit as part of a guided drill.
            $table->foreignId('challenge_run_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('drill_step')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('hit_at');
            $table->timestamps();

            $table->unique(['user_id', 'uuid']);
            $table->index(['user_id', 'hit_at']);
            $table->index(['user_id', 'putter_id', 'distance_ft']);
            $table->index(['user_id', 'context', 'distance_ft']);
            $table->index(['challenge_run_id', 'hit_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('putts');
    }
};
