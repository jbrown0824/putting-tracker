<?php

namespace App\Actions;

use App\Enums\PuttContext;
use App\Enums\SurfaceType;
use App\Models\Putter;
use App\Models\PuttingSession;
use App\Models\User;
use Illuminate\Support\Carbon;

class ResolvePuttingSession
{
    /**
     * A gap longer than this between putts starts a new session.
     */
    public const IDLE_GAP_MINUTES = 90;

    /**
     * Sessions are implicit: a putt joins the player's most recent session sharing
     * its context, surface and putter unless too much time has passed, in which case
     * a fresh session opens.
     *
     * Switching putters splits the session even mid-practice, so a session's make rate
     * always describes exactly one putter on one surface.
     *
     * @param  array{location?: string|null}  $attributes
     */
    public function execute(
        User $user,
        PuttContext $context,
        ?SurfaceType $surfaceType,
        Putter $putter,
        Carbon $hitAt,
        array $attributes = [],
    ): PuttingSession {
        $candidate = $user->puttingSessions()
            ->where('context', $context)
            ->where('surface_type', $surfaceType)
            ->where('putter_id', $putter->id)
            ->where('started_at', '<=', $hitAt)
            ->latest('started_at')
            ->first();

        if ($candidate !== null && $this->isStillOpen($candidate, $hitAt)) {
            return $candidate;
        }

        return $user->puttingSessions()->create([
            'putter_id' => $putter->id,
            'context' => $context,
            'surface_type' => $surfaceType,
            'location' => $attributes['location'] ?? null,
            'started_at' => $hitAt,
        ]);
    }

    private function isStillOpen(PuttingSession $session, Carbon $hitAt): bool
    {
        $lastActivity = $session->putts()->max('hit_at');

        $reference = $lastActivity !== null
            ? Carbon::parse($lastActivity)
            : $session->started_at;

        return $reference->diffInMinutes($hitAt, absolute: true) <= self::IDLE_GAP_MINUTES;
    }
}
