<?php

namespace App\Http\Controllers;

use App\Models\Challenge;
use App\Services\PuttStats;
use Illuminate\Contracts\View\View;

class LogController extends Controller
{
    public function index(PuttStats $stats): View
    {
        $challenge = Challenge::current();

        return view('log', [
            'challenge' => $challenge,
            'progress' => $challenge !== null ? $stats->progress($challenge) : null,
        ]);
    }
}
