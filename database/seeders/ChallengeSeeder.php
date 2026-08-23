<?php

namespace Database\Seeders;

use App\Models\Challenge;
use Illuminate\Database\Seeder;

class ChallengeSeeder extends Seeder
{
    public function run(): void
    {
        Challenge::query()->updateOrCreate(
            ['name' => '2,000 Putt Challenge'],
            [
                'start_date' => '2026-08-23',
                'end_date' => '2026-09-19',
                'target_total' => 2000,
                'target_outside_min' => 400,
            ],
        );
    }
}
