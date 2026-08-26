<?php

namespace App\Http\Controllers;

use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Models\Challenge;
use App\Models\PuttingSession;
use App\Services\PutterComparison;
use App\Services\PuttingProfile;
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
     * The last context viewed. "Both" is stored as PuttContext::ANY rather than as
     * an absent key, so choosing it is distinguishable from never having chosen.
     */
    private const CONTEXT_KEY = 'stats.context';

    /**
     * How many recent sessions the scope picker offers.
     */
    private const SELECTABLE_SESSIONS = 50;

    public function index(
        Request $request,
        PuttStats $stats,
        PutterComparison $comparison,
        PuttingProfile $profile,
    ): View {
        $challenge = Challenge::current();
        $session = $this->resolveSession($request);

        // A session already belongs to exactly one putter and one context, so it
        // decides both rather than the sticky toggles — and viewing one leaves
        // them undisturbed.
        $putter = $session?->putter ?? $this->resolvePutter($request);
        $context = $session?->context ?? $this->resolveContext($request);

        $scoped = $session !== null
            ? $stats->forSession($session)
            : $stats->forPutter($putter)->inContext($context);

        return view('stats', [
            'challenge' => $challenge,
            'putter' => $putter,
            'context' => $context,
            'session' => $session,
            'sessions' => $this->selectableSessions(),
            // Progress and pace track the challenge, which counts every putt
            // regardless of putter or context, so they stay on the unscoped service.
            'progress' => $challenge !== null ? $stats->progress($challenge) : null,
            'dailyVolume' => $challenge !== null ? $stats->dailyVolume($challenge) : collect(),
            'dial' => $scoped->missDial(),
            'speedVsLine' => $scoped->speedVsLine(),
            'lineMissCauses' => $scoped->lineMissCauses(),
            'byDistance' => $scoped->byDistance(),
            'insideVsOutside' => $scoped->insideVsOutside(),
            'fiftyPercentDistance' => $scoped->fiftyPercentDistance(),
            'insights' => $scoped->insights(),
            'contextBreakdown' => $comparison->contextBreakdown(),
            'profileAxes' => $profile->build($scoped),
        ]);
    }

    public function compare(Request $request, PutterComparison $comparison): View
    {
        $context = $this->resolveContext($request);
        $scoped = $comparison->inContext($context);

        return view('stats.compare', [
            'context' => $context,
            'headline' => $scoped->headline(),
            'byDistance' => $scoped->byDistance(),
            'verdict' => $scoped->verdict(),
            'strengths' => $scoped->strengths(),
            'contextBreakdown' => $comparison->contextBreakdown(),
            'profiles' => $scoped->profiles(),
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

    /**
     * Inside, outside, or null for both. Sticky like the putter, so a round of
     * outside practice keeps showing outside stats until told otherwise.
     */
    private function resolveContext(Request $request): ?PuttContext
    {
        $requested = $request->query('context')
            ?? $request->session()->get(self::CONTEXT_KEY)
            ?? PuttContext::ANY;

        $context = PuttContext::tryFrom((string) $requested);

        $request->session()->put(self::CONTEXT_KEY, $context?->value ?? PuttContext::ANY);

        return $context;
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
