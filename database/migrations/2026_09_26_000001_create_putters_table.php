<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('putters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('head_type');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->decimal('length_in', 4, 1)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Retired rather than deleted, so the history hit with it survives.
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'retired_at', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('putters');
    }
};
