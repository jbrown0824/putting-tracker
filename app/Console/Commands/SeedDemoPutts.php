<?php

namespace App\Console\Commands;

use App\Actions\GenerateDemoPutts;
use Illuminate\Console\Command;

class SeedDemoPutts extends Command
{
    protected $signature = 'putts:demo
        {--days=14 : How many days of practice to generate}
        {--profile= : lag, charger, puller, pusher, elite, struggling, or random}
        {--keep-window : Leave the challenge start date untouched}
        {--fresh : Required to delete putts that already exist}';

    protected $description = 'Replace all putts with a fresh random dataset for testing the stats page';

    public function handle(GenerateDemoPutts $generate): int
    {
        try {
            $summary = $generate->execute(
                days: max(1, (int) $this->option('days')),
                profileName: $this->option('profile'),
                alignChallengeWindow: ! $this->option('keep-window'),
                replaceExisting: (bool) $this->option('fresh'),
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Seeded %d putts across %d sessions.', $summary['putts'], $summary['sessions']));

        $this->table(['Setting', 'Value'], [
            ['Profile', $summary['profile']],
            ['Blade putts', $summary['per_putter']['blade']],
            ['Mallet putts', $summary['per_putter']['mallet']],
            ['Days', $summary['days']],
            ['Starts on', $summary['starts_on']],
            ['Speed tendency', $this->describeSpeed($summary['speed_tendency'])],
            ['Line tendency', $this->describeLine($summary['line_tendency'])],
            ['Outdoor penalty', $summary['outdoor_penalty'].' pts'],
        ]);

        $this->line('  Run again for a different dataset. Restore the real challenge window with:');
        $this->line('  <fg=gray>php artisan db:seed --class=ChallengeSeeder</>');

        return self::SUCCESS;
    }

    private function describeSpeed(float $tendency): string
    {
        return match (true) {
            $tendency <= -0.35 => sprintf('leaves putts short (%.2f)', $tendency),
            $tendency >= 0.35 => sprintf('runs putts past (%.2f)', $tendency),
            default => sprintf('balanced (%.2f)', $tendency),
        };
    }

    private function describeLine(float $tendency): string
    {
        return match (true) {
            $tendency <= -0.35 => sprintf('misses left (%.2f)', $tendency),
            $tendency >= 0.35 => sprintf('misses right (%.2f)', $tendency),
            default => sprintf('balanced (%.2f)', $tendency),
        };
    }
}
