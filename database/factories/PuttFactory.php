<?php

namespace Database\Factories;

use App\Enums\ClockPosition;
use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Models\Putt;
use App\Models\PuttingSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** @extends Factory<Putt> */
class PuttFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'putting_session_id' => PuttingSession::factory(),
            // Owner and putter follow the session, so a factory putt is always consistent with it.
            'user_id' => fn (array $attributes): int => PuttingSession::query()->findOrFail($attributes['putting_session_id'])->user_id,
            'putter_id' => fn (array $attributes): int => PuttingSession::query()->findOrFail($attributes['putting_session_id'])->putter_id,
            'distance_ft' => fake()->randomElement([3, 5, 8, 10, 12, 15, 20]),
            'result' => fake()->randomElement(PuttResult::cases()),
            'context' => PuttContext::Inside,
            // Null by default so tests have to opt in to a tagged putt.
            'clock_position' => null,
            'hit_at' => Carbon::now(),
        ];
    }

    public function at(ClockPosition $position): static
    {
        return $this->state(fn () => [
            'clock_position' => $position,
            'slope' => $position->slope(),
        ]);
    }

    public function sunk(): static
    {
        return $this->state(fn () => ['result' => PuttResult::Sunk]);
    }

    public function outside(): static
    {
        return $this->state(fn () => ['context' => PuttContext::Outside]);
    }

    public function atDistance(int $feet): static
    {
        return $this->state(fn () => ['distance_ft' => $feet]);
    }
}
