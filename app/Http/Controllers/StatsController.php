<?php

namespace App\Http\Controllers;

use App\Enums\PuttContext;
use App\Models\Challenge;
use App\Services\PuttStats;
use Illuminate\Contracts\View\View;

class StatsController extends Controller
{
    public function index(PuttStats $stats): View
    {
        $challenge = Challenge::current();

        return view('stats', [
            'challenge' => $challenge,
            'progress' => $challenge !== null ? $stats->progress($challenge) : null,
            'dial' => $stats->missDial(),
            'speedVsLine' => $stats->speedVsLine(),
            'byDistance' => $stats->byDistance(),
            'insideByDistance' => $stats->byDistance(PuttContext::Inside),
            'outsideByDistance' => $stats->byDistance(PuttContext::Outside),
            'insideVsOutside' => $stats->insideVsOutside(),
            'fiftyPercentDistance' => $stats->fiftyPercentDistance(),
            'dailyVolume' => $challenge !== null ? $stats->dailyVolume($challenge) : collect(),
            'insights' => $stats->insights(),
        ]);
    }
}
