<?php

namespace App\Http\Controllers;

use App\Enums\PuttContext;
use App\Models\Putter;
use App\Models\PuttingSession;
use App\Models\User;
use App\Services\AdjustedRate;
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
     * "All putters" is stored as ALL_PUTTERS rather than as an absent key, so
     * choosing it is distinguishable from never having chosen.
     */
    private const SESSION_KEY = 'stats.putter';

    public const ALL_PUTTERS = 'all';

    /**
     * The last context viewed. "Both" is stored as PuttContext::ANY rather than as
     * an absent key, for the same reason.
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
        AdjustedRate $adjusted,
    ): View {
        $user = $request->user();
        $stats = $stats->forUser($user);
        $session = $this->resolveSession($request, $user);

        // A session already belongs to exactly one putter and one context, so it
        // decides both rather than the sticky toggles — and viewing one leaves
        // them undisturbed.
        $putter = $session?->putter ?? $this->resolvePutter($request, $user);
        $context = $session?->context ?? $this->resolveContext($request);

        $scoped = $session !== null
            ? $stats->forSession($session)
            : $stats->forPutter($putter)->inContext($context);

        return view('stats', [
            'putter' => $putter,
            'putters' => $this->switchablePutters($user),
            'context' => $context,
            'session' => $session,
            'sessions' => $this->selectableSessions($user),
            'dial' => $scoped->missDial(),
            'speedVsLine' => $scoped->speedVsLine(),
            'lineMissCauses' => $scoped->lineMissCauses(),
            'byDistance' => $scoped->byDistance(),
            'insideVsOutside' => $scoped->insideVsOutside(),
            'fiftyPercentDistance' => $scoped->fiftyPercentDistance(),
            'insights' => $scoped->insights(),
            'contextBreakdown' => $comparison->forUser($user)->contextBreakdown($this->puttersWithPutts($user)),
            'profileAxes' => $profile->build($scoped),
            'clockPositions' => $scoped->byClockPosition(),
            // Levelled against every putt in the same context, so the number shown
            // for one putter means the same thing as the number shown for another.
            //
            // The reference follows the context filter rather than always being the
            // whole dataset: an outside-only scope shares no strata with a reference
            // dominated by indoor putts, so coverage would never clear its threshold
            // and the card would simply never appear on a filtered page.
            'adjusted' => $adjusted->standardised($scoped, $stats->inContext($session?->context ?? $context)),
        ]);
    }

    public function compare(Request $request, PutterComparison $comparison): View
    {
        $user = $request->user();
        $context = $this->resolveContext($request);
        $candidates = $this->puttersWithPutts($user);

        $first = $this->pickPutter($candidates, $request->query('first'), $candidates->get(0));
        $second = $this->pickPutter(
            $candidates->reject(fn (Putter $putter): bool => $putter->is($first)),
            $request->query('second'),
            $candidates->first(fn (Putter $putter): bool => ! $putter->is($first)),
        );

        $data = [
            'context' => $context,
            'putters' => $this->switchablePutters($user),
            'candidates' => $candidates,
            'first' => $first,
            'second' => $second,
        ];

        if ($first === null || $second === null) {
            return view('stats.compare', $data);
        }

        $pair = $comparison->forUser($user)->between($first, $second);
        $scoped = $pair->inContext($context);

        return view('stats.compare', $data + [
            'headline' => $scoped->headline(),
            'byDistance' => $scoped->byDistance(),
            'verdict' => $scoped->verdict(),
            'strengths' => $scoped->strengths(),
            'contextBreakdown' => $pair->contextBreakdown(),
            'profiles' => $scoped->profiles(),
            'matchedRates' => $scoped->matchedRates(),
        ]);
    }

    /**
     * The session being viewed, or null for overall. Deliberately not remembered in
     * the Laravel session the way the putter is: coming back days later and still
     * being pinned to one night's practice would be baffling.
     */
    private function resolveSession(Request $request, User $user): ?PuttingSession
    {
        if (! $request->filled('session')) {
            return null;
        }

        return $user->puttingSessions()->with('putter')->find($request->query('session'));
    }

    /**
     * Recent sessions for the scope picker, newest first.
     *
     * @return Collection<int, PuttingSession>
     */
    private function selectableSessions(User $user): Collection
    {
        return $user->puttingSessions()
            ->with('putter')
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

    /**
     * One putter, or null for all of them pooled. Retired putters stay reachable
     * here because their history is still worth reading.
     */
    private function resolvePutter(Request $request, User $user): ?Putter
    {
        $requested = $request->query('putter')
            ?? $request->session()->get(self::SESSION_KEY)
            ?? self::ALL_PUTTERS;

        $putter = $requested === self::ALL_PUTTERS
            ? null
            : $user->putters()->find((int) $requested);

        $request->session()->put(self::SESSION_KEY, $putter?->id ?? self::ALL_PUTTERS);

        return $putter;
    }

    /**
     * Putters worth offering in the stats switch: every active one, plus any
     * retired one that has history to read.
     *
     * @return Collection<int, Putter>
     */
    private function switchablePutters(User $user): Collection
    {
        return $user->putters()
            ->where(fn ($query) => $query->whereNull('retired_at')->orWhereHas('putts'))
            ->ordered()
            ->get();
    }

    /**
     * Putters with at least one putt, most used first — the natural default pair
     * to compare is the two you have hit the most.
     *
     * @return Collection<int, Putter>
     */
    private function puttersWithPutts(User $user): Collection
    {
        return $user->putters()
            ->whereHas('putts')
            ->withCount('putts')
            ->orderByDesc('putts_count')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Putter>  $candidates
     */
    private function pickPutter(Collection $candidates, mixed $requested, ?Putter $fallback): ?Putter
    {
        return $candidates->firstWhere('id', (int) $requested) ?? $fallback;
    }
}
