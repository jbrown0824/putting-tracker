<?php

namespace App\Http\Controllers;

use App\Models\Putt;
use App\Models\PuttingSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SessionController extends Controller
{
    public function index(Request $request): View
    {
        $sessions = $request->user()->puttingSessions()
            ->with('putter')
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
        Gate::authorize('view', $session);

        $session->load(['putter', 'putts' => fn ($query) => $query->latest('hit_at')]);

        return view('sessions.show', ['session' => $session]);
    }

    public function destroyPutt(PuttingSession $session, Putt $putt): RedirectResponse
    {
        Gate::authorize('delete', $session);
        abort_unless($putt->putting_session_id === $session->id, 404);

        $putt->delete();

        return back()->with('status', 'Putt deleted.');
    }

    public function destroy(PuttingSession $session): RedirectResponse
    {
        Gate::authorize('delete', $session);

        $session->delete();

        return redirect()->route('sessions.index')->with('status', 'Session deleted.');
    }
}
