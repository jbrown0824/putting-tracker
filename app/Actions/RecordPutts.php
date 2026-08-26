<?php

namespace App\Actions;

use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
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
                        'slope' => $putt['slope'] ?? null,
                        'break_direction' => $putt['break_direction'] ?? null,
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
