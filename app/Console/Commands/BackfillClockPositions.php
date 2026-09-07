<?php

namespace App\Console\Commands;

use App\Enums\ClockPosition;
use App\Enums\PuttSlope;
use App\Models\Putt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillClockPositions extends Command
{
    protected $signature = 'putts:backfill-positions
        {--force : Actually write; without it the command only reports}';

    protected $description = 'Recover clock positions from the slope and break direction recorded before the ring existed';

    public function handle(): int
    {
        if (! Schema::hasColumn('putts', 'break_direction')) {
            $this->components->info('break_direction is already gone, so there is nothing left to recover.');

            return self::SUCCESS;
        }

        $candidates = Putt::query()
            ->whereNull('clock_position')
            ->whereNotNull('break_direction')
            ->get(['id', 'break_direction', 'slope']);

        if ($candidates->isEmpty()) {
            $this->components->info('No putts are carrying a break direction that has not already been converted.');

            return self::SUCCESS;
        }

        /** @var array<string, array<int, int>> $byPosition */
        $byPosition = [];
        $unrecoverable = 0;

        foreach ($candidates as $putt) {
            $position = ClockPosition::fromLegacy(
                $putt->break_direction,
                $putt->slope instanceof PuttSlope ? $putt->slope : PuttSlope::tryFrom((string) $putt->slope),
            );

            if ($position === null) {
                $unrecoverable++;

                continue;
            }

            $byPosition[$position->value][] = $putt->id;
        }

        $this->table(
            ['Position', 'Reads as', 'Putts'],
            collect($byPosition)
                ->map(fn (array $ids, string $value): array => [
                    ClockPosition::from($value)->clockLabel(),
                    ClockPosition::from($value)->label(),
                    count($ids),
                ])
                ->values()
                ->all(),
        );

        $recoverable = collect($byPosition)->sum(fn (array $ids): int => count($ids));

        if ($unrecoverable > 0) {
            // A break with no slope narrows the ball to three positions. Guessing
            // between them would be inventing data, so those rows stay null.
            $this->components->warn(sprintf(
                '%d putts have a break direction but no slope, so their position cannot be pinned down. They stay unclassified.',
                $unrecoverable,
            ));
        }

        if (! $this->option('force')) {
            $this->components->info(sprintf('Would recover %d positions. Nothing written.', $recoverable));
            $this->line('  Re-run with <fg=yellow>--force</> to write them.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($byPosition): void {
            foreach ($byPosition as $value => $ids) {
                $position = ClockPosition::from($value);

                Putt::query()->whereIn('id', $ids)->update([
                    'clock_position' => $position,
                    'slope' => $position->slope(),
                ]);
            }
        });

        $this->components->info(sprintf('Recovered %d clock positions.', $recoverable));
        $this->line('  <fg=gray>break_direction is now redundant, but keep the column until you have checked these look right.</>');

        return self::SUCCESS;
    }
}
