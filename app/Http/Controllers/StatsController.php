<?php

namespace App\Http\Controllers;

use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Models\Challenge;
use App\Models\PuttingSession;
use App\Services\PutterComparison;
use App\Services\PuttStats;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class StatsController extends Controller
{
    /**
     * The last putter viewed, so the toggle survives a trip to the log screen.
     */
    private const SESSION_KEY = 'stats.putter';

    /**
     * How many recent sessions the scope picker offers.
     */
    private const SELECTABLE_SESSIONS = 50;

    public function index(Request $request, PuttStats $stats): View
    {
        $challenge = Challenge::current();
        $session = $this->resolveSession($request);

        // A session already belongs to exactly one putter, so it decides the label
        // rather than the sticky toggle — and viewing one does not disturb it.
        $putter = $session?->putter ?? $this->resolvePutter($request);

        $scoped = $session !== null
            ? $stats->forSession($session)
            : $stats->forPutter($putter);

        return view('stats', [
            'challenge' => $challenge,
            'putter' => $putter,
            'session' => $session,
            'sessions' => $this->selectableSessions(),
            // Progress and pace track the challenge, which counts every putt
            // regardless of putter, so they stay on the unscoped service.
            'progress' => $challenge !== null ? $stats->progress($challenge) : null,
            'dailyVolume' => $challenge !== null ? $stats->dailyVolume($challenge) : collect(),
            'dial' => $scoped->missDial(),
            'speedVsLine' => $scoped->speedVsLine(),
            'byDistance' => $scoped->byDistance(),
            'insideByDistance' => $scoped->byDistance(PuttContext::Inside),
            'outsideByDistance' => $scoped->byDistance(PuttContext::Outside),
            'insideVsOutside' => $scoped->insideVsOutside(),
            'fiftyPercentDistance' => $scoped->fiftyPercentDistance(),
            'insights' => $scoped->insights(),
        ]);
    }

    public function compare(PutterComparison $comparison): View
    {
        return view('stats.compare', [
            'headline' => $comparison->headline(),
            'byDistance' => $comparison->byDistance(),
            'verdict' => $comparison->verdict(),
            'strengths' => $comparison->strengths(),
        ]);
    }

    /**
     * The session being viewed, or null for overall. Deliberately not remembered in
     * the Laravel session the way the putter is: coming back days later and still
     * being pinned to one night's practice would be baffling.
     */
    private function resolveSession(Request $request): ?PuttingSession
    {
        if (! $request->filled('session')) {
            return null;
        }

        return PuttingSession::query()->find($request->query('session'));
    }

    /**
     * Recent sessions for the scope picker, newest first.
     *
     * @return Collection<int, PuttingSession>
     */
    private function selectableSessions(): Collection
    {
        return PuttingSession::query()
            ->withCount('putts')
            ->whereHas('putts')
            ->latest('started_at')
            ->limit(self::SELECTABLE_SESSIONS)
            ->get();
    }

    private function resolvePutter(Request $request): Putter
    {
        $putter = Putter::tryFrom((string) $request->query('putter'))
            ?? Putter::tryFrom((string) $request->session()->get(self::SESSION_KEY))
            ?? Putter::default();

        $request->session()->put(self::SESSION_KEY, $putter->value);

        return $putter;
    }
}
