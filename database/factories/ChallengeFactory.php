<?php

namespace Database\Factories;

use App\Enums\ChallengeKind;
use App\Enums\DrillMissRule;
use App\Enums\DrillOrder;
use App\Models\Challenge;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Challenge> */
class ChallengeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => '2,000 Putt Challenge',
            'kind' => ChallengeKind::Goals,
            'starts_on' => Carbon::today(),
            'ends_on' => Carbon::today()->addDays(27),
            'drill_makes_required' => 1,
            'drill_attempts' => 1,
            'drill_rounds' => 1,
        ];
    }

    public function drill(): static
    {
        return $this->state(fn () => [
            'name' => 'Ladder',
            'kind' => ChallengeKind::Drill,
            'drill_on_miss' => DrillMissRule::Restart,
            'drill_order' => DrillOrder::Sequential,
        ]);
    }

    public function openEnded(): static
    {
        return $this->state(fn () => ['ends_on' => null]);
    }
}
