<?php

namespace App\Services;

use App\Enums\PuttResult;
use App\Models\User;
use Illuminate\Support\Carbon;

class PracticeSummary
{
    /**
     * The running tally the logger shows: today and this week, counted in the
     * player's own calendar rather than UTC, so a late-evening session lands on the
     * day it was actually hit.
     *
     * @return array{today: array{total: int, sunk: int}, week: array{total: int, sunk: int}}
     */
    public function for(User $user): array
    {
        $now = Carbon::now($user->timezone);

        return [
            'today' => $this->countBetween($user, $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'week' => $this->countBetween($user, $now->copy()->startOfWeek(), $now->copy()->endOfWeek()),
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
