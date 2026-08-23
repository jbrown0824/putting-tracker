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
            $table->uuid('uuid')->unique();
            $table->foreignId('putting_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('distance_ft');
            $table->string('result');
            $table->string('context');
            $table->string('slope')->nullable();
            $table->string('break_direction')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('hit_at')->index();
            $table->timestamps();

            $table->index(['context', 'distance_ft']);
            $table->index(['result', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('putts');
    }
};
