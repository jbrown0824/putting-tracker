<?php

namespace App\Services;

use App\Enums\GoalMetric;
use App\Enums\GoalPeriod;
use App\Enums\PuttResult;
use App\Models\Challenge;
use App\Models\ChallengeGoal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How far along a challenge is, read from the putts that match it right now.
 *
 * Nothing is stored against the putt. A putt counts towards every challenge whose
 * filters it matches, so challenges stack, and editing a challenge's filters
 * simply recounts history.
 *
 * Days are the player's own calendar days, grouped in PHP rather than SQL so the
 * time zone shift stays portable between SQLite and Postgres.
 */
class ChallengeProgress
{
    /**
     * An open-ended challenge could run for years; the chart only needs the recent past.
     */
    private const MAX_SERIES_DAYS = 90;

    /**
     * @return array<string, mixed>
     */
    public function for(Challenge $challenge, ?Carbon $now = null): array
    {
        $challenge->loadMissing(['user', 'putters', 'goals', 'steps']);

        $timezone = $challenge->user->timezone;
        $today = ($now ?? Carbon::now())->copy()->setTimezone($timezone)->startOfDay();
        $putts = $this->putts($challenge, $timezone);
        $runDates = $this->completedRunDates($challenge, $timezone);
        $window = $this->window($challenge, $today);

        return [
            'challenge' => $challenge,
            'state' => $window['state'],
            'days_total' => $window['days_total'],
            'days_elapsed' => $window['days_elapsed'],
            'days_remaining' => $window['days_remaining'],
            'attempts' => $putts->count(),
            'goals' => $challenge->goals
                ->map(fn (ChallengeGoal $goal): array => $this->goal($challenge, $goal, $putts, $runDates, $window, $today))
                ->all(),
            'drill' => $challenge->isDrill() ? $this->drillSummary($challenge) : null,
        ];
    }

    /**
     * The compact shape the logger's focus bar and eligibility chips read.
     *
     * @return array<string, mixed>
     */
    public function forLogger(Challenge $challenge, ?Carbon $now = null): array
    {
        $progress = $this->for($challenge, $now);

        return [
            'id' => $challenge->id,
            'name' => $challenge->name,
            'kind' => $challenge->kind->value,
            'state' => $progress['state'],
            'days_remaining' => $progress['days_remaining'],
            'eligibility' => $challenge->eligibility(),
            'goals' => array_map(fn (array $goal): array => [
                'id' => $goal['goal']->id,
                'label' => $goal['label'],
                'metric' => $goal['goal']->metric->value,
                'period' => $goal['goal']->period->value,
                'context' => $goal['goal']->context?->value,
                'min_distance_ft' => $goal['goal']->min_distance_ft,
                'max_distance_ft' => $goal['goal']->max_distance_ft,
                'target' => $goal['target'],
                'value' => $goal['value'],
                'status' => $goal['status'],
                'per_day_needed' => $goal['pace']['per_day_needed'] ?? null,
                'current' => $goal['current'],
                'periods' => $goal['periods'],
            ], $progress['goals']),
            'drill' => $challenge->isDrill() ? [
                'on_miss' => $challenge->drill_on_miss?->value,
                'order' => $challenge->drill_order?->value,
                'makes_required' => $challenge->drill_makes_required,
                'rounds' => $challenge->drill_rounds,
                'steps' => $challenge->steps->map(fn ($step): array => [
                    'distance_ft' => $step->distance_ft,
                    'clock_position' => $step->clock_position?->value,
                    'makes_required' => $step->makes_required,
                ])->values()->all(),
                'completed_runs' => $progress['drill']['completed_runs'],
            ] : null,
        ];
    }

    /**
     * @return Collection<int, array{date: string, made: bool, context: string, distance_ft: int}>
     */
    private function putts(Challenge $challenge, string $timezone): Collection
    {
        return $challenge->eligiblePutts()
            ->orderBy('hit_at')
            ->orderBy('id')
            ->get(['hit_at', 'result', 'context', 'distance_ft'])
            ->map(fn ($putt): array => [
                'date' => $putt->hit_at->copy()->setTimezone($timezone)->toDateString(),
                'made' => $putt->result === PuttResult::Sunk,
                'context' => $putt->context->value,
                'distance_ft' => $putt->distance_ft,
            ]);
    }

    /**
     * @return Collection<int, string> the local date each completed run finished on
     */
    private function completedRunDates(Challenge $challenge, string $timezone): Collection
    {
        if (! $challenge->isDrill()) {
            return collect();
        }

        return $challenge->runs()
            ->whereNotNull('completed_at')
            ->pluck('completed_at')
            ->map(fn ($completedAt): string => Carbon::parse($completedAt)->setTimezone($timezone)->toDateString())
            ->filter(fn (string $date): bool => $date >= $challenge->starts_on->toDateString()
                && ($challenge->ends_on === null || $date <= $challenge->ends_on->toDateString()))
            ->values();
    }

    /**
     * @return array{state: string, start: Carbon, last: Carbon|null, days_total: int|null, days_elapsed: int, days_remaining: int|null}
     */
    private function window(Challenge $challenge, Carbon $today): array
    {
        $start = Carbon::parse($challenge->starts_on->toDateString(), $today->getTimezone());
        $end = $challenge->ends_on !== null ? Carbon::parse($challenge->ends_on->toDateString(), $today->getTimezone()) : null;

        $state = match (true) {
            ! $challenge->hasStarted($today) => 'upcoming',
            $challenge->hasEnded($today) => 'ended',
            default => 'active',
        };

        // The last day that has happened so far: today while running, the end once over.
        $last = match ($state) {
            'upcoming' => null,
            'ended' => $end,
            default => $today->copy(),
        };

        return [
            'state' => $state,
            'start' => $start,
            'last' => $last,
            'days_total' => $end !== null ? (int) $start->diffInDays($end) + 1 : null,
            'days_elapsed' => $last !== null ? (int) $start->diffInDays($last) + 1 : 0,
            'days_remaining' => match (true) {
                $end === null => null,
                $state === 'ended' => 0,
                $state === 'upcoming' => (int) $start->diffInDays($end) + 1,
                default => (int) $today->diffInDays($end) + 1,
            },
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $putts
     * @param  Collection<int, string>  $runDates
     * @param  array<string, mixed>  $window
     * @return array<string, mixed>
     */
    private function goal(Challenge $challenge, ChallengeGoal $goal, Collection $putts, Collection $runDates, array $window, Carbon $today): array
    {
        $covered = $putts->filter(fn (array $putt): bool => $goal->covers($putt))->values();

        $base = [
            'goal' => $goal,
            'label' => $goal->describe(),
            'target' => $goal->target,
            'series' => $this->series($challenge, $goal, $covered, $runDates, $window),
        ];

        return $goal->period === GoalPeriod::Total
            ? $base + $this->totalGoal($goal, $covered, $runDates, $window)
            : $base + $this->periodicGoal($challenge, $goal, $covered, $runDates, $window, $today);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $putts
     * @param  Collection<int, string>  $runDates
     * @param  array<string, mixed>  $window
     * @return array<string, mixed>
     */
    private function totalGoal(ChallengeGoal $goal, Collection $putts, Collection $runDates, array $window): array
    {
        $value = $this->measure($goal->metric, $putts, $runDates);
        $met = $this->meets($goal, $value, $putts->count());
        $remaining = max(0, $goal->target - $value);

        $expected = $window['days_total'] !== null
            ? $goal->target * min(1, $window['days_elapsed'] / max(1, $window['days_total']))
            : null;

        $accumulates = in_array($goal->metric, [GoalMetric::Attempts, GoalMetric::Makes, GoalMetric::DaysPractised, GoalMetric::DrillRunsCompleted], true);
        $daysLeft = $window['days_remaining'];

        $status = match (true) {
            $met => 'complete',
            $window['state'] === 'upcoming' => 'upcoming',
            $window['state'] === 'ended' => 'failed',
            $goal->metric === GoalMetric::DaysPractised && $daysLeft !== null && $remaining > $daysLeft => 'failed',
            $accumulates && $expected !== null && $value < $expected => 'behind',
            default => 'on_track',
        };

        return [
            'value' => $value,
            'remaining' => $remaining,
            'met' => $met,
            'percent' => $goal->target > 0 ? min(100.0, round($value / $goal->target * 100, 1)) : 100.0,
            'status' => $status,
            'current' => null,
            'periods' => null,
            'pace' => [
                'expected' => $expected !== null && $accumulates ? (int) round($expected) : null,
                'per_day_needed' => $accumulates && $daysLeft !== null && $daysLeft > 0 && ! $met
                    ? (int) ceil($remaining / $daysLeft)
                    : null,
            ],
        ];
    }

    /**
     * A daily or weekly goal is met or missed afresh each period. The current
     * period is still open, so it is reported separately and never counted as a
     * miss until it closes.
     *
     * @param  Collection<int, array<string, mixed>>  $putts
     * @param  Collection<int, string>  $runDates
     * @param  array<string, mixed>  $window
     * @return array<string, mixed>
     */
    private function periodicGoal(Challenge $challenge, ChallengeGoal $goal, Collection $putts, Collection $runDates, array $window, Carbon $today): array
    {
        $length = $goal->period === GoalPeriod::Daily ? 1 : 7;
        $keyFor = fn (string $date): int => intdiv((int) $window['start']->diffInDays(Carbon::parse($date, $window['start']->getTimezone())), $length);

        $byPeriod = $putts->groupBy(fn (array $putt): int => $keyFor($putt['date']));
        $runsByPeriod = $runDates->groupBy(fn (string $date): int => $keyFor($date));

        $elapsed = $window['last'] !== null ? $keyFor($window['last']->toDateString()) + 1 : 0;
        $total = $window['days_total'] !== null ? (int) ceil($window['days_total'] / $length) : null;
        $required = $goal->periods_required !== null ? min($goal->periods_required, $total ?? PHP_INT_MAX) : $total;

        $periods = [];

        for ($key = 0; $key < $elapsed; $key++) {
            $periodPutts = $byPeriod->get($key, collect());
            $value = $this->measure($goal->metric, $periodPutts, $runsByPeriod->get($key, collect()));

            $periods[$key] = [
                'value' => $value,
                'met' => $this->meets($goal, $value, $periodPutts->count()),
            ];
        }

        $open = $window['state'] === 'active' ? array_key_last($periods) : null;
        $closed = $open !== null ? array_slice($periods, 0, -1, true) : $periods;
        $metCount = count(array_filter($periods, fn (array $period): bool => $period['met']));
        $future = $total !== null ? max(0, $total - $elapsed) : null;
        $openUnmet = $open !== null && ! $periods[$open]['met'] ? 1 : 0;
        $achievable = $future !== null ? $metCount + $future + $openUnmet : null;

        $status = match (true) {
            $window['state'] === 'upcoming' => 'upcoming',
            $required !== null && $metCount >= $required => 'complete',
            $required !== null && $achievable < $required => 'failed',
            $window['state'] === 'ended' => 'failed',
            default => 'on_track',
        };

        $current = null;

        if ($open !== null) {
            $periodEnd = $window['start']->copy()->addDays(($open + 1) * $length - 1);

            $current = [
                'label' => $goal->period === GoalPeriod::Daily ? 'Today' : 'This week',
                'value' => $periods[$open]['value'],
                'remaining' => max(0, $goal->target - $periods[$open]['value']),
                'met' => $periods[$open]['met'],
                'days_left' => (int) $today->diffInDays($challenge->ends_on !== null ? min($periodEnd, Carbon::parse($challenge->ends_on->toDateString(), $today->getTimezone())) : $periodEnd) + 1,
            ];
        }

        return [
            'value' => $current['value'] ?? ($periods !== [] ? end($periods)['value'] : 0),
            'remaining' => $current['remaining'] ?? 0,
            'met' => $status === 'complete',
            'percent' => $required ? min(100.0, round($metCount / $required * 100, 1)) : null,
            'status' => $status,
            'current' => $current,
            'periods' => [
                'met' => $metCount,
                'missed' => count(array_filter($closed, fn (array $period): bool => ! $period['met'])),
                'elapsed' => $elapsed,
                'total' => $total,
                'required' => $required,
                'streak' => $this->streak($periods, $open),
            ],
            'pace' => null,
        ];
    }

    /**
     * Consecutive periods met, counting back from the latest closed one — or from
     * the open one if it is already met, so hitting today's target ticks the
     * streak up straight away.
     *
     * @param  array<int, array{value: int|float, met: bool}>  $periods
     */
    private function streak(array $periods, ?int $open): int
    {
        $keys = array_reverse(array_keys($periods));

        if ($open !== null && ! $periods[$open]['met']) {
            array_shift($keys);
        }

        $streak = 0;

        foreach ($keys as $key) {
            if (! $periods[$key]['met']) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * One point per day, for the charts: the day's own figure plus the running
     * total against the straight-line pace a total goal requires.
     *
     * @param  Collection<int, array<string, mixed>>  $putts
     * @param  Collection<int, string>  $runDates
     * @param  array<string, mixed>  $window
     * @return array<int, array<string, mixed>>
     */
    private function series(Challenge $challenge, ChallengeGoal $goal, Collection $putts, Collection $runDates, array $window): array
    {
        $end = $challenge->ends_on !== null
            ? Carbon::parse($challenge->ends_on->toDateString(), $window['start']->getTimezone())
            : $window['last'];

        if ($end === null) {
            return [];
        }

        $start = $window['start']->copy();

        if ($start->diffInDays($end) >= self::MAX_SERIES_DAYS) {
            $start = $end->copy()->subDays(self::MAX_SERIES_DAYS - 1);
        }

        $byDate = $putts->groupBy('date');
        $runsByDate = $runDates->countBy();
        $lastDate = $window['last']?->toDateString();
        $perDay = $window['days_total'] !== null ? $goal->target / max(1, $window['days_total']) : null;
        // Only a count adds up day over day; a rate or a streak does not.
        $accumulates = in_array($goal->metric, [GoalMetric::Attempts, GoalMetric::Makes, GoalMetric::DaysPractised, GoalMetric::DrillRunsCompleted], true);
        $cumulative = $accumulates
            ? $this->measure($goal->metric, $putts->filter(fn (array $putt): bool => $putt['date'] < $start->toDateString()), $runDates->filter(fn (string $date): bool => $date < $start->toDateString()))
            : 0;
        $series = [];

        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $date = $day->toDateString();
            $dayPutts = $byDate->get($date, collect());
            $value = $this->measure($goal->metric, $dayPutts, collect(array_fill(0, $runsByDate->get($date, 0), $date)));
            $happened = $lastDate !== null && $date <= $lastDate;

            if ($happened) {
                $cumulative += $value;
            }

            $series[] = [
                'date' => $date,
                'label' => $day->format('M j'),
                'value' => $happened ? $value : null,
                'attempts' => $dayPutts->count(),
                'met' => $happened && $goal->period === GoalPeriod::Daily ? $this->meets($goal, $value, $dayPutts->count()) : null,
                'cumulative' => $happened && $accumulates ? $cumulative : null,
                'target_cumulative' => $accumulates && $goal->period === GoalPeriod::Total && $perDay !== null
                    ? (int) round($perDay * ((int) $window['start']->diffInDays($day) + 1))
                    : null,
            ];
        }

        return $series;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $putts
     * @param  Collection<int, string>  $runDates
     */
    private function measure(GoalMetric $metric, Collection $putts, Collection $runDates): int|float
    {
        return match ($metric) {
            GoalMetric::Attempts => $putts->count(),
            GoalMetric::Makes => $putts->where('made', true)->count(),
            GoalMetric::MakePercent => $putts->isEmpty() ? 0.0 : round($putts->where('made', true)->count() / $putts->count() * 100, 1),
            GoalMetric::MakeStreak => $this->longestStreak($putts),
            GoalMetric::DaysPractised => $putts->pluck('date')->unique()->count(),
            GoalMetric::DrillRunsCompleted => $runDates->count(),
        };
    }

    /**
     * A make rate only counts once there is a sample behind it; anything else
     * simply has to reach the target.
     */
    private function meets(ChallengeGoal $goal, int|float $value, int $attempts): bool
    {
        if ($goal->metric === GoalMetric::MakePercent && $attempts < max(1, $goal->min_attempts ?? 1)) {
            return false;
        }

        return $value >= $goal->target;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $putts  in the order they were hit
     */
    private function longestStreak(Collection $putts): int
    {
        $best = 0;
        $run = 0;

        foreach ($putts as $putt) {
            $run = $putt['made'] ? $run + 1 : 0;
            $best = max($best, $run);
        }

        return $best;
    }

    /**
     * @return array{completed_runs: int, started_runs: int, fewest_putts: int|null, last_completed_at: Carbon|null}
     */
    private function drillSummary(Challenge $challenge): array
    {
        $runs = $challenge->runs()->withCount('putts')->get();
        $completed = $runs->whereNotNull('completed_at');

        return [
            'completed_runs' => $completed->count(),
            'started_runs' => $runs->count(),
            'fewest_putts' => $completed->min('putts_count'),
            'last_completed_at' => $completed->max('completed_at'),
        ];
    }
}
