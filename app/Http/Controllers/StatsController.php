<?php

namespace App\Http\Controllers;

use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Models\Challenge;
use App\Services\PutterComparison;
use App\Services\PuttStats;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    /**
     * The last putter viewed, so the toggle survives a trip to the log screen.
     */
    private const SESSION_KEY = 'stats.putter';

    public function index(Request $request, PuttStats $stats): View
    {
        $challenge = Challenge::current();
        $putter = $this->resolvePutter($request);
        $scoped = $stats->forPutter($putter);

        return view('stats', [
            'challenge' => $challenge,
            'putter' => $putter,
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

    private function resolvePutter(Request $request): Putter
    {
        $putter = Putter::tryFrom((string) $request->query('putter'))
            ?? Putter::tryFrom((string) $request->session()->get(self::SESSION_KEY))
            ?? Putter::default();

        $request->session()->put(self::SESSION_KEY, $putter->value);

        return $putter;
    }
}
