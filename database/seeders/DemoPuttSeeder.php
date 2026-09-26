<?php

namespace Database\Seeders;

use App\Actions\GenerateDemoPutts;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoPuttSeeder extends Seeder
{
    public function __construct(private GenerateDemoPutts $generate) {}

    /**
     * Give the demo player a fresh random dataset for exercising the stats page.
     *
     * Deliberately not wired into DatabaseSeeder — run it explicitly:
     * php artisan db:seed --class=DemoPuttSeeder
     * php artisan putts:demo demo@example.com --days=21 --profile=lag --fresh
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo Golfer', 'password' => 'password'],
        );

        $summary = $this->generate->execute(
            user: $user,
            days: (int) env('DEMO_DAYS', 14),
            profileName: env('DEMO_PROFILE'),
            replaceExisting: true,
        );

        $this->command?->info(sprintf(
            'Seeded %d putts across %d sessions for demo@example.com (password "password"; %d days, profile: %s).',
            $summary['putts'],
            $summary['sessions'],
            $summary['days'],
            $summary['profile'],
        ));
    }
}
