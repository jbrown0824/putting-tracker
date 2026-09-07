<?php

namespace App\Actions;

use App\Enums\ClockPosition;
use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use App\Enums\PuttSlope;
use App\Models\Putt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecordPutts
{
    public function __construct(private ResolvePuttingSession $resolveSession) {}

    /**
     * Persist a batch of putts from the client queue.
     *
     * The client generates each uuid, so replaying a batch after a dropped
     * connection updates the existing row instead of duplicating it.
     *
     * @param  array<int, array<string, mixed>>  $putts
     * @return array<int, string> the uuids that are now stored
     */
    public function execute(array $putts): array
    {
        return DB::transaction(function () use ($putts): array {
            $stored = [];

            foreach ($putts as $putt) {
                $hitAt = Carbon::parse($putt['hit_at']);
                $context = PuttContext::from($putt['context']);

                // Clients queue putts offline, so a bundle predating putter tracking
                // can still post batches without one.
                $putter = isset($putt['putter'])
                    ? Putter::from($putt['putter'])
                    : Putter::default();

                $result = PuttResult::from($putt['result']);
                $position = $this->resolvePosition($putt);

                $session = $this->resolveSession->execute($context, $putter, $hitAt, [
                    'location' => $putt['location'] ?? null,
                    'surface' => $putt['surface'] ?? null,
                ]);

                Putt::query()->updateOrCreate(
                    ['uuid' => $putt['uuid']],
                    [
                        'putting_session_id' => $session->id,
                        'distance_ft' => $putt['distance_ft'],
                        'result' => $result,
                        'miss_cause' => $this->resolveMissCause($putt, $result),
                        'context' => $context,
                        'putter' => $putter,
                        'slope' => $this->resolveSlope($putt, $position),
                        'clock_position' => $position,
                        'notes' => $putt['notes'] ?? null,
                        'hit_at' => $hitAt,
                    ],
                );

                $stored[] = $putt['uuid'];
            }

            return $stored;
        });
    }

    /**
     * The position the client sent, or the one implied by the fields it used to
     * send instead.
     *
     * A phone running a bundle from before the ring existed posts slope and
     * break_direction. Together those pin down exactly one position, so its putts
     * arrive properly classified rather than landing in the unclassified bucket
     * for want of a field it had no way to know about.
     *
     * @param  array<string, mixed>  $putt
     */
    private function resolvePosition(array $putt): ?ClockPosition
    {
        if (isset($putt['clock_position'])) {
            return ClockPosition::from($putt['clock_position']);
        }

        return ClockPosition::fromLegacy(
            $putt['break_direction'] ?? null,
            isset($putt['slope']) ? PuttSlope::from($putt['slope']) : null,
        );
    }

    /**
     * The clock position already fixes the slope, so it wins whenever one was sent.
     *
     * A client running a bundle from before the ring existed still posts its own
     * slope, and that is kept rather than discarded — the field stays populated
     * across the changeover instead of going dark for the putts in flight.
     *
     * @param  array<string, mixed>  $putt
     */
    private function resolveSlope(array $putt, ?ClockPosition $position): ?PuttSlope
    {
        if ($position !== null) {
            return $position->slope();
        }

        return isset($putt['slope']) ? PuttSlope::from($putt['slope']) : null;
    }

    /**
     * A cause is only meaningful on a left or right miss. Anything else is dropped
     * rather than rejected, so a client that sends a stale one still gets its putt
     * stored instead of having the whole batch fail validation.
     *
     * @param  array<string, mixed>  $putt
     */
    private function resolveMissCause(array $putt, PuttResult $result): ?LineMissCause
    {
        if (! isset($putt['miss_cause']) || ! LineMissCause::appliesTo($result)) {
            return null;
        }

        return LineMissCause::from($putt['miss_cause']);
    }
}
