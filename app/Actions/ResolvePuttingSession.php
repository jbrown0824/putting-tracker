<?php

namespace App\Actions;

use App\Enums\PuttContext;
use App\Models\PuttingSession;
use Illuminate\Support\Carbon;

class ResolvePuttingSession
{
    /**
     * A gap longer than this between putts starts a new session.
     */
    public const IDLE_GAP_MINUTES = 90;

    /**
     * Sessions are implicit: a putt joins the most recent session sharing its context
     * unless too much time has passed, in which case a fresh session opens.
     *
     * @param  array{location?: string|null, surface?: string|null}  $attributes
     */
    public function execute(PuttContext $context, Carbon $hitAt, array $attributes = []): PuttingSession
    {
        $candidate = PuttingSession::query()
            ->where('context', $context)
            ->where('started_at', '<=', $hitAt)
            ->latest('started_at')
            ->first();

        if ($candidate !== null && $this->isStillOpen($candidate, $hitAt)) {
            return $candidate;
        }

        return PuttingSession::query()->create([
            'context' => $context,
            'location' => $attributes['location'] ?? null,
            'surface' => $attributes['surface'] ?? null,
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
