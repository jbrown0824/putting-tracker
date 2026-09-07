<?php

namespace App\Console\Commands;

use App\Enums\ClockPosition;
use App\Enums\PuttContext;
use App\Enums\PuttSlope;
use App\Models\Putt;
use Illuminate\Console\Command;

class BackfillFlatPositions extends Command
{
    protected $signature = 'putts:backfill-flat
        {--context=inside : Which context to backfill, inside or outside}
        {--force : Actually write; without it the command only reports}';

    protected $description = 'Tag already-logged putts as played on a flat surface, for practice that had no fall line';

    public function handle(): int
    {
        $context = PuttContext::tryFrom((string) $this->option('context'));

        if ($context === null) {
            $this->error('Context must be inside or outside.');

            return self::FAILURE;
        }

        $query = Putt::query()
            ->where('context', $context)
            ->whereNull('clock_position');

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->components->info('Nothing to backfill — every '.$context->value.' putt already has a position.');

            return self::SUCCESS;
        }

        $this->warnAboutSlope($context);

        if (! $this->option('force')) {
            $this->components->info(sprintf('Would tag %d %s putts as flat. Nothing written.', $count, $context->value));
            $this->line('  Re-run with <fg=yellow>--force</> once you are sure the surface has no slope.');

            return self::SUCCESS;
        }

        // Only ever fills the gap. A putt that already carries a position was
        // recorded deliberately and is never overwritten.
        $updated = $query->update([
            'clock_position' => ClockPosition::Flat,
            'slope' => PuttSlope::Flat,
        ]);

        $this->components->info(sprintf('Tagged %d %s putts as flat.', $updated, $context->value));

        return self::SUCCESS;
    }

    /**
     * A putt already tagged uphill or downhill contradicts a flat surface, which
     * usually means the practice area does have a slope and this backfill is the
     * wrong tool.
     */
    private function warnAboutSlope(PuttContext $context): void
    {
        $sloped = Putt::query()
            ->where('context', $context)
            ->whereNull('clock_position')
            ->whereIn('slope', [PuttSlope::Uphill, PuttSlope::Downhill])
            ->count();

        if ($sloped > 0) {
            $this->components->warn(sprintf(
                '%d of these are already tagged uphill or downhill. If that was real, the surface is not flat and this backfill would overwrite it.',
                $sloped,
            ));
        }
    }
}
