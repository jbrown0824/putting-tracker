<?php

namespace Database\Factories;

use App\Enums\PuttContext;
use App\Models\PuttingSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<PuttingSession> */
class PuttingSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'context' => PuttContext::Inside,
            'location' => null,
            'surface' => null,
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
