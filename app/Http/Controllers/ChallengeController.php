<?php

namespace App\Http\Controllers;

use App\Actions\SaveChallenge;
use App\Enums\ChallengeKind;
use App\Http\Requests\SaveChallengeRequest;
use App\Models\Challenge;
use App\Services\ChallengeProgress;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class ChallengeController extends Controller
{
    public function index(Request $request, ChallengeProgress $progress): View
    {
        $user = $request->user();
        $today = Carbon::now($user->timezone)->startOfDay();

        $challenges = $user->challenges()
            ->with(['user', 'putters', 'goals', 'steps'])
            ->orderByDesc('starts_on')
            ->get()
            ->map(fn (Challenge $challenge): array => $progress->for($challenge, $today));

        return view('challenges.index', [
            'active' => $challenges->filter(fn (array $row): bool => $row['state'] === 'active' && $row['challenge']->archived_at === null)->values(),
            'upcoming' => $challenges->filter(fn (array $row): bool => $row['state'] === 'upcoming' && $row['challenge']->archived_at === null)->sortBy(fn (array $row) => $row['challenge']->starts_on)->values(),
            'finished' => $challenges->filter(fn (array $row): bool => $row['state'] === 'ended' || $row['challenge']->archived_at !== null)->values(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('challenges.form', [
            'challenge' => new Challenge([
                'kind' => ChallengeKind::Goals,
                'starts_on' => Carbon::now($request->user()->timezone)->startOfDay(),
            ]),
            'putters' => $request->user()->putters()->active()->ordered()->get(),
        ]);
    }

    public function store(SaveChallengeRequest $request, SaveChallenge $save): RedirectResponse
    {
        $challenge = $save->execute($request->user(), $request->validated());

        return redirect()->route('challenges.show', $challenge)->with('status', 'Challenge created.');
    }

    public function show(Challenge $challenge, ChallengeProgress $progress): View
    {
        Gate::authorize('view', $challenge);

        return view('challenges.show', [
            'progress' => $progress->for($challenge),
            'recentRuns' => $challenge->isDrill()
                ? $challenge->runs()->withCount('putts')->latest('started_at')->limit(10)->get()
                : collect(),
        ]);
    }

    public function edit(Request $request, Challenge $challenge): View
    {
        Gate::authorize('update', $challenge);

        return view('challenges.form', [
            'challenge' => $challenge->load(['putters', 'goals', 'steps']),
            // Retired putters stay offered when the challenge already names them.
            'putters' => $request->user()->putters()
                ->where(fn ($query) => $query->whereNull('retired_at')->orWhereIn('id', $challenge->putters->modelKeys()))
                ->ordered()
                ->get(),
        ]);
    }

    public function update(SaveChallengeRequest $request, Challenge $challenge, SaveChallenge $save): RedirectResponse
    {
        Gate::authorize('update', $challenge);

        $save->execute($request->user(), $request->validated(), $challenge);

        return redirect()->route('challenges.show', $challenge)->with('status', 'Challenge saved.');
    }

    /**
     * Archiving takes a challenge off the logger without losing it. Its progress
     * is read from the putts, which are never touched either way.
     */
    public function archive(Challenge $challenge): RedirectResponse
    {
        Gate::authorize('update', $challenge);

        $challenge->update(['archived_at' => $challenge->archived_at === null ? now() : null]);

        return back()->with('status', $challenge->archived_at === null ? 'Challenge restored.' : 'Challenge archived.');
    }

    public function destroy(Challenge $challenge): RedirectResponse
    {
        Gate::authorize('delete', $challenge);

        $challenge->delete();

        return redirect()->route('challenges.index')->with('status', 'Challenge deleted. Your putts are untouched.');
    }
}
