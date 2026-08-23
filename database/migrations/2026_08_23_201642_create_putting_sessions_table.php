<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('putting_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('context')->index();
            $table->string('location')->nullable();
            $table->string('surface')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('putting_sessions');
    }
};
