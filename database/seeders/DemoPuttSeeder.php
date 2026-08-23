<?php

namespace Database\Seeders;

use App\Actions\GenerateDemoPutts;
use Illuminate\Database\Seeder;

class DemoPuttSeeder extends Seeder
{
    public function __construct(private GenerateDemoPutts $generate) {}

    /**
     * Replace all putts with a fresh random dataset for exercising the stats page.
     *
     * Deliberately not wired into DatabaseSeeder — run it explicitly:
     * php artisan db:seed --class=DemoPuttSeeder
     * php artisan putts:demo --days=21 --profile=lag
     */
    public function run(): void
    {
        $summary = $this->generate->execute(
            days: (int) env('DEMO_DAYS', 14),
            profileName: env('DEMO_PROFILE'),
        );

        $this->command?->info(sprintf(
            'Seeded %d putts across %d sessions (%d days, profile: %s).',
            $summary['putts'],
            $summary['sessions'],
            $summary['days'],
            $summary['profile'],
        ));
    }
}
