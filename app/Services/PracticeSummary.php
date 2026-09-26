<?php

namespace App\Services;

use App\Enums\PuttResult;
use App\Models\User;
use Illuminate\Support\Carbon;

class PracticeSummary
{
    public function __construct(private ChallengeProgress $progress) {}

    /**
     * Everything the logger shows above the dial: today and this week, counted in
     * the player's own calendar rather than UTC so a late-evening session lands on
     * the day it was actually hit, plus every challenge running today.
     *
     * @return array{today: array{total: int, sunk: int}, week: array{total: int, sunk: int}, challenges: array<int, array<string, mixed>>}
     */
    public function for(User $user): array
    {
        $now = Carbon::now($user->timezone);

        return [
            'today' => $this->countBetween($user, $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'week' => $this->countBetween($user, $now->copy()->startOfWeek(), $now->copy()->endOfWeek()),
            'challenges' => $user->challenges()
                ->activeOn($now)
                ->with(['user', 'putters', 'goals', 'steps'])
                ->orderBy('starts_on')
                ->get()
                ->map(fn ($challenge): array => $this->progress->forLogger($challenge))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{total: int, sunk: int}
     */
    private function countBetween(User $user, Carbon $from, Carbon $to): array
    {
        $row = $user->putts()
            ->whereBetween('hit_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when result = '".PuttResult::Sunk->value."' then 1 else 0 end) as sunk")
            ->first();

        return [
            'total' => (int) $row->total,
            'sunk' => (int) $row->sunk,
        ];
    }
}
