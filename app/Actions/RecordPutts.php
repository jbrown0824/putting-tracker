<?php

namespace App\Actions;

use App\Enums\PuttContext;
use App\Enums\Putter;
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

                $session = $this->resolveSession->execute($context, $putter, $hitAt, [
                    'location' => $putt['location'] ?? null,
                    'surface' => $putt['surface'] ?? null,
                ]);

                Putt::query()->updateOrCreate(
                    ['uuid' => $putt['uuid']],
                    [
                        'putting_session_id' => $session->id,
                        'distance_ft' => $putt['distance_ft'],
                        'result' => $putt['result'],
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
}
