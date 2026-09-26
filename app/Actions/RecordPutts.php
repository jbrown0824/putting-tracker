<?php

namespace App\Actions;

use App\Enums\ClockPosition;
use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Enums\PuttSlope;
use App\Enums\SurfaceType;
use App\Models\ChallengeRun;
use App\Models\Putter;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecordPutts
{
    public function __construct(
        private ResolvePuttingSession $resolveSession,
        private SettleDrillRuns $settleRuns,
    ) {}

    /**
     * Persist a batch of putts from the client queue.
     *
     * The client generates each uuid, so replaying a batch after a dropped
     * connection updates the existing row instead of duplicating it. Uuids are
     * unique per player, so nobody else's putt can ever be overwritten.
     *
     * @param  array<int, array<string, mixed>>  $putts
     * @return array<int, string> the uuids that are now stored
     */
    public function execute(User $user, array $putts): array
    {
        $putters = $user->putters()->get()->keyBy('id');
        $drills = $user->challenges()->where('kind', 'drill')->pluck('id')->flip();

        return DB::transaction(function () use ($user, $putts, $putters, $drills): array {
            $stored = [];
            $runs = [];

            foreach ($putts as $putt) {
                $hitAt = Carbon::parse($putt['hit_at']);
                $context = PuttContext::from($putt['context']);
                $surfaceType = $this->resolveSurfaceType($putt, $context);
                $putter = $this->resolvePutter($user, $putters, $putt);
                $result = PuttResult::from($putt['result']);
                $position = $this->resolvePosition($putt);

                $session = $this->resolveSession->execute($user, $context, $surfaceType, $putter, $hitAt, [
                    'location' => $putt['location'] ?? null,
                ]);

                $run = $this->resolveRun($user, $drills, $putt, $hitAt);

                if ($run !== null) {
                    $runs[$run->id] = $run;
                }

                $user->putts()->updateOrCreate(
                    ['uuid' => $putt['uuid']],
                    [
                        'putting_session_id' => $session->id,
                        'putter_id' => $putter->id,
                        'distance_ft' => $putt['distance_ft'],
                        'result' => $result,
                        'miss_cause' => $this->resolveMissCause($putt, $result),
                        'context' => $context,
                        'surface_type' => $surfaceType,
                        'slope' => $this->resolveSlope($putt, $position),
                        'clock_position' => $position,
                        'challenge_run_id' => $run?->id,
                        'drill_step' => $run !== null ? ($putt['drill_step'] ?? null) : null,
                        'notes' => $putt['notes'] ?? null,
                        'hit_at' => $hitAt,
                    ],
                );

                $stored[] = $putt['uuid'];
            }

            $this->settleRuns->execute($runs);

            return $stored;
        });
    }

    /**
     * The drill run a putt was hit in, created on first sight since the phone
     * starts runs offline. A run naming a challenge that is not one of this
     * player's drills is dropped rather than rejected — the putt itself still counts.
     *
     * @param  Collection<int, int>  $drills  the player's drill challenge ids, as keys
     * @param  array<string, mixed>  $putt
     */
    private function resolveRun(User $user, Collection $drills, array $putt, Carbon $hitAt): ?ChallengeRun
    {
        if (! isset($putt['challenge_run_uuid'], $putt['challenge_id']) || ! $drills->has((int) $putt['challenge_id'])) {
            return null;
        }

        $run = $user->challengeRuns()->firstOrCreate(
            ['uuid' => $putt['challenge_run_uuid']],
            ['challenge_id' => (int) $putt['challenge_id'], 'started_at' => $hitAt],
        );

        return $run->challenge_id === (int) $putt['challenge_id'] ? $run : null;
    }

    /**
     * The putter the phone named, or the player's default when it named none or
     * one that is not theirs any more. A queued putt is never rejected over its
     * putter, or it would sit in the phone's queue failing forever.
     *
     * @param  Collection<int, Putter>  $putters
     * @param  array<string, mixed>  $putt
     */
    private function resolvePutter(User $user, Collection $putters, array $putt): Putter
    {
        $putter = isset($putt['putter_id']) ? $putters->get((int) $putt['putter_id']) : null;

        return $putter ?? $user->defaultPutter();
    }

    /**
     * A surface type that contradicts the context is dropped rather than rejected:
     * the context is the fact every stat depends on, the surface only refines it.
     *
     * @param  array<string, mixed>  $putt
     */
    private function resolveSurfaceType(array $putt, PuttContext $context): ?SurfaceType
    {
        $type = isset($putt['surface_type']) ? SurfaceType::from($putt['surface_type']) : null;

        return $type?->context() === $context ? $type : null;
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
     * A stale client that sent only a slope keeps it rather than losing it.
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
