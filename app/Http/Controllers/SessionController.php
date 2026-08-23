<?php

namespace App\Http\Controllers;

use App\Models\Putt;
use App\Models\PuttingSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SessionController extends Controller
{
    public function index(): View
    {
        $sessions = PuttingSession::query()
            ->withCount([
                'putts',
                'putts as sunk_count' => fn ($query) => $query->sunk(),
            ])
            ->latest('started_at')
            ->paginate(25);

        return view('sessions.index', ['sessions' => $sessions]);
    }

    public function show(PuttingSession $session): View
    {
        $session->load(['putts' => fn ($query) => $query->latest('hit_at')]);

        return view('sessions.show', ['session' => $session]);
    }

    public function destroyPutt(PuttingSession $session, Putt $putt): RedirectResponse
    {
        abort_unless($putt->putting_session_id === $session->id, 404);

        $putt->delete();

        return back()->with('status', 'Putt deleted.');
    }

    public function destroy(PuttingSession $session): RedirectResponse
    {
        $session->delete();

        return redirect()->route('sessions.index')->with('status', 'Session deleted.');
    }
}
