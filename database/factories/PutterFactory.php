<?php

namespace Database\Factories;

use App\Enums\PutterHeadType;
use App\Models\Putter;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Putter> */
class PutterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Newport 2', 'Spider X', 'White Hot #7', 'Phantom 5', 'Anser']),
            'head_type' => PutterHeadType::Blade,
            'is_default' => false,
            'sort_order' => 0,
            'retired_at' => null,
        ];
    }

    public function blade(): static
    {
        return $this->state(fn () => ['name' => 'Blade', 'head_type' => PutterHeadType::Blade]);
    }

    public function mallet(): static
    {
        return $this->state(fn () => ['name' => 'Mallet', 'head_type' => PutterHeadType::Mallet]);
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function retired(): static
    {
        return $this->state(fn () => ['retired_at' => now()]);
    }
}
