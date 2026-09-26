<?php

namespace Database\Factories;

use App\Enums\PuttContext;
use App\Models\Putter;
use App\Models\PuttingSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<PuttingSession> */
class PuttingSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'putter_id' => Putter::factory(),
            // Always the putter's owner, so a session can never straddle two players.
            'user_id' => fn (array $attributes): int => Putter::query()->findOrFail($attributes['putter_id'])->user_id,
            'context' => PuttContext::Inside,
            'surface_type' => null,
            'location' => null,
            'notes' => null,
            'started_at' => Carbon::now(),
            'ended_at' => null,
        ];
    }

    public function outside(): static
    {
        return $this->state(fn () => ['context' => PuttContext::Outside]);
    }
}
