<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A challenge is a set of eligibility filters plus either goals (log freely,
     * matching putts count) or drill steps (the logger guides each putt).
     *
     * Which putts count is decided when progress is read, never stored against the
     * putt, so challenges stack and editing a filter recounts history correctly.
     */
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('kind');
            $table->date('starts_on');
            // Null for an open-ended challenge, such as a standing daily habit.
            $table->date('ends_on')->nullable();

            // Eligibility filters. Null means any.
            $table->json('contexts')->nullable();
            $table->json('surface_types')->nullable();
            $table->unsignedSmallInteger('min_distance_ft')->nullable();
            $table->unsignedSmallInteger('max_distance_ft')->nullable();

            // Drill rules. Ignored on a goals challenge.
            $table->string('drill_on_miss')->nullable();
            $table->string('drill_order')->nullable();
            $table->unsignedSmallInteger('drill_makes_required')->default(1);
            $table->unsignedSmallInteger('drill_rounds')->default(1);

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'starts_on', 'ends_on']);
        });

        // No rows for a challenge means any putter counts.
        Schema::create('challenge_putter', function (Blueprint $table) {
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('putter_id')->constrained()->cascadeOnDelete();

            $table->primary(['challenge_id', 'putter_id']);
        });

        Schema::create('challenge_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->string('metric');
            $table->string('period');
            $table->unsignedInteger('target');
            // Narrows the goal within the challenge's own filters, e.g. "400 of the
            // 2,000 must be outside".
            $table->string('context')->nullable();
            $table->unsignedSmallInteger('min_distance_ft')->nullable();
            $table->unsignedSmallInteger('max_distance_ft')->nullable();
            // Make-rate goals only count once there is a sample behind them.
            $table->unsignedInteger('min_attempts')->nullable();
            // For daily and weekly goals: how many periods must be met. Null means all.
            $table->unsignedSmallInteger('periods_required')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('challenge_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->unsignedSmallInteger('distance_ft');
            $table->string('clock_position')->nullable();
            // Null inherits the challenge's drill_makes_required.
            $table->unsignedSmallInteger('makes_required')->nullable();
            $table->timestamps();

            $table->index(['challenge_id', 'sort_order']);
        });

        // One attempt at a drill. The phone generates the uuid so a run started
        // offline syncs idempotently, exactly like a putt.
        Schema::create('challenge_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'uuid']);
            $table->index(['challenge_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_runs');
        Schema::dropIfExists('challenge_steps');
        Schema::dropIfExists('challenge_goals');
        Schema::dropIfExists('challenge_putter');
        Schema::dropIfExists('challenges');
    }
};
