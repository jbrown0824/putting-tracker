<?php

namespace App\Services;

use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use Illuminate\Support\Collection;

class PutterComparison
{
    /**
     * A distance only joins the matched sample once both putters have this many
     * attempts there, so a single lucky rep cannot swing the comparison.
     */
    public const MIN_ATTEMPTS_PER_DISTANCE = 5;

    /**
     * Below this many attempts with either putter no recommendation is offered.
     */
    public const MIN_ATTEMPTS_PER_PUTTER = 100;

    /**
     * The matched sample has to reach this before the significance test means anything.
     */
    public const MIN_MATCHED_SAMPLE = 40;

    /**
     * Two-sided 95% critical value.
     */
    private const CONFIDENCE_Z = 1.96;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $headline = null;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $byDistance = null;

    private ?PuttContext $context = null;

    public function __construct(private PuttStats $stats) {}

    /**
     * Narrow the whole comparison to inside or outside putts, so "which putter is
     * better on a real green" gets its own answer rather than being averaged away.
     *
     * contextBreakdown() deliberately ignores this — comparing the two contexts is
     * its entire job.
     */
    public function inContext(?PuttContext $context): self
    {
        $clone = clone $this;
        $clone->context = $context;
        $clone->headline = null;
        $clone->byDistance = null;

        return $clone;
    }

    public function context(): ?PuttContext
    {
        return $this->context;
    }

    /**
     * How each putter holds up when you leave the carpet: inside and outside make
     * rates side by side, plus the points given up moving between them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function contextBreakdown(): array
    {
        $breakdown = [];

        foreach (Putter::cases() as $putter) {
            // Deliberately not context-scoped: this row spans both sides.
            $scoped = $this->stats->forPutter($putter);
            $inside = $this->contextSummary($scoped, PuttContext::Inside);
            $outside = $this->contextSummary($scoped, PuttContext::Outside);

            $breakdown[$putter->value] = [
                'putter' => $putter,
                'inside' => $inside,
                'outside' => $outside,
                'comparable' => $inside['attempts'] > 0 && $outside['attempts'] > 0,
                'drop' => round($inside['make_percent'] - $outside['make_percent'], 1),
            ];
        }

        return $breakdown;
    }

    /**
     * Side-by-side totals for each putter, keyed by putter value.
     *
     * Memoised because verdict() and strengths() both lean on it, and each call
     * costs a handful of aggregate queries per putter.
     *
     * @return array<string, array<string, mixed>>
     */
    public function headline(): array
    {
        if ($this->headline !== null) {
            return $this->headline;
        }

        $headline = [];

        foreach (Putter::cases() as $putter) {
            $scoped = $this->scopedStats($putter);
            $dial = $scoped->missDial();
            $split = $scoped->speedVsLine();

            $attempts = array_sum(array_column($dial, 'count'));

            $headline[$putter->value] = [
                'putter' => $putter,
                'attempts' => $attempts,
                'sunk' => $dial[PuttResult::Sunk->value]['count'],
                'make_percent' => $dial[PuttResult::Sunk->value]['percent'],
                'inside' => $this->contextSummary($scoped, PuttContext::Inside),
                'outside' => $this->contextSummary($scoped, PuttContext::Outside),
                'fifty_percent_distance' => $scoped->fiftyPercentDistance(),
                'speed_percent' => $split['speed_percent'],
                'line_percent' => $split['line_percent'],
                'lip_out_percent' => $dial[PuttResult::LipOut->value]['percent'],
                'miss_left' => $dial[PuttResult::MissLeft->value]['count'],
                'miss_right' => $dial[PuttResult::MissRight->value]['count'],
            ];
        }

        return $this->headline = $headline;
    }

    /**
     * Make rate per distance for both putters, limited to distances played enough
     * with each. Comparing only where they overlap stops a putter looking better
     * merely because it got the shorter putts.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byDistance(): Collection
    {
        if ($this->byDistance !== null) {
            return $this->byDistance;
        }

        $blade = $this->scopedStats(Putter::Blade)->byDistance()->keyBy('distance_ft');
        $mallet = $this->scopedStats(Putter::Mallet)->byDistance()->keyBy('distance_ft');

        return $this->byDistance = $blade->keys()
            ->intersect($mallet->keys())
            ->sort()
            ->values()
            ->map(fn (int $distance): array => [
                'distance_ft' => $distance,
                'blade_percent' => $blade[$distance]['make_percent'],
                'mallet_percent' => $mallet[$distance]['make_percent'],
                'blade_attempts' => $blade[$distance]['attempts'],
                'mallet_attempts' => $mallet[$distance]['attempts'],
                // The smaller of the two counts: neither putter gets credit for reps
                // the other never took at this distance.
                'weight' => min($blade[$distance]['attempts'], $mallet[$distance]['attempts']),
                'gap' => round($mallet[$distance]['make_percent'] - $blade[$distance]['make_percent'], 1),
            ])
            ->filter(fn (array $row): bool => $row['blade_attempts'] >= self::MIN_ATTEMPTS_PER_DISTANCE
                && $row['mallet_attempts'] >= self::MIN_ATTEMPTS_PER_DISTANCE)
            ->values();
    }

    /**
     * Which putter to keep in the bag, or an honest refusal to call it.
     *
     * The matched rates are pooled across distances weighted by the shared sample
     * size, then compared with a two-proportion z-test. That is a Mantel-Haenszel
     * style approximation rather than an exact stratified test — close enough to
     * stop the page recommending a putter on a handful of lucky putts, which is the
     * only job it has here.
     *
     * @return array<string, mixed>
     */
    public function verdict(): array
    {
        $matched = $this->byDistance();
        $headline = $this->headline();

        $bladeAttempts = $headline[Putter::Blade->value]['attempts'];
        $malletAttempts = $headline[Putter::Mallet->value]['attempts'];
        $sample = (int) $matched->sum('weight');

        $shortfall = $this->shortfall($bladeAttempts, $malletAttempts, $sample);

        if ($shortfall !== null) {
            return $shortfall;
        }

        $bladeRate = $this->matchedRate($matched, 'blade_percent');
        $malletRate = $this->matchedRate($matched, 'mallet_percent');
        $gap = round($malletRate - $bladeRate, 1);
        $leader = $gap >= 0 ? Putter::Mallet : Putter::Blade;
        $z = $this->zScore($bladeRate, $malletRate, $sample);

        if (abs($z) < self::CONFIDENCE_Z) {
            return [
                'state' => 'too_close',
                'putter' => null,
                'blade_percent' => $bladeRate,
                'mallet_percent' => $malletRate,
                'gap' => $gap,
                'sample' => $sample,
                'z' => round($z, 2),
                'message' => sprintf(
                    'Too close to call. Across %d matched putts the %s is ahead by just %s points, which is inside the noise — play whichever you prefer.',
                    $sample,
                    strtolower($leader->label()),
                    abs($gap),
                ),
            ];
        }

        return [
            'state' => 'recommended',
            'putter' => $leader,
            'blade_percent' => $bladeRate,
            'mallet_percent' => $malletRate,
            'gap' => $gap,
            'sample' => $sample,
            'z' => round($z, 2),
            'message' => sprintf(
                'Play the %s. At the distances you have hit with both, it makes %s%% against the %s\'s %s%% — %s points better over %d matched putts, which is more than chance explains.',
                strtolower($leader->label()),
                $leader === Putter::Mallet ? $malletRate : $bladeRate,
                strtolower($leader->other()->label()),
                $leader === Putter::Mallet ? $bladeRate : $malletRate,
                abs($gap),
                $sample,
            ),
        ];
    }

    /**
     * What each putter does better than the other, strongest signal first and keyed
     * by putter value. Every entry is gated on its own sample, so a thin dataset
     * yields fewer claims rather than weaker ones.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function strengths(): array
    {
        $matched = $this->byDistance();
        $headline = $this->headline();

        $items = collect([
            $this->rangeStrength($matched, 'Short range', fn (int $d): bool => $d <= 8, 'inside 8 ft'),
            $this->rangeStrength($matched, 'Long range', fn (int $d): bool => $d >= 15, 'from 15 ft and out'),
            $this->lineControlStrength($headline),
            $this->outdoorStrength($headline),
            $this->reachStrength($headline),
            $this->aimStrength($headline),
        ])->filter();

        $strengths = [];

        foreach (Putter::cases() as $putter) {
            $strengths[$putter->value] = $items
                ->where('putter', $putter)
                ->sortByDesc('magnitude')
                ->values()
                ->all();
        }

        return $strengths;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $matched
     */
    private function matchedRate(Collection $matched, string $key): float
    {
        $weight = $matched->sum('weight');

        if ($weight <= 0) {
            return 0.0;
        }

        return round($matched->sum(fn (array $row): float => $row['weight'] * $row[$key]) / $weight, 1);
    }

    /**
     * Two-proportion z-test with both arms sharing the matched sample size.
     */
    private function zScore(float $bladePercent, float $malletPercent, int $sample): float
    {
        if ($sample <= 0) {
            return 0.0;
        }

        $blade = $bladePercent / 100;
        $mallet = $malletPercent / 100;
        $pooled = ($blade + $mallet) / 2;
        $variance = $pooled * (1 - $pooled) * (2 / $sample);

        if ($variance <= 0.0) {
            return 0.0;
        }

        return ($mallet - $blade) / sqrt($variance);
    }

    /**
     * The reason a verdict cannot be given yet, or null when there is enough data.
     *
     * @return array<string, mixed>|null
     */
    private function shortfall(int $bladeAttempts, int $malletAttempts, int $sample): ?array
    {
        $thinnest = min($bladeAttempts, $malletAttempts);

        if ($thinnest < self::MIN_ATTEMPTS_PER_PUTTER) {
            $behind = $bladeAttempts <= $malletAttempts ? Putter::Blade : Putter::Mallet;

            return [
                'state' => 'insufficient_data',
                'putter' => null,
                'needed' => self::MIN_ATTEMPTS_PER_PUTTER - $thinnest,
                'sample' => $sample,
                'message' => sprintf(
                    'Not enough data to call it. Log %d more putts with the %s and this will start comparing them.',
                    self::MIN_ATTEMPTS_PER_PUTTER - $thinnest,
                    strtolower($behind->label()),
                ),
            ];
        }

        if ($sample < self::MIN_MATCHED_SAMPLE) {
            return [
                'state' => 'insufficient_data',
                'putter' => null,
                'needed' => self::MIN_MATCHED_SAMPLE - $sample,
                'sample' => $sample,
                'message' => 'Not enough overlap to call it. You have hit both putters plenty, but rarely from the same distances — work through the same ladder with each and this will fill in.',
            ];
        }

        return null;
    }

    /**
     * A matched make-rate edge over one slice of the distance ladder.
     *
     * @param  Collection<int, array<string, mixed>>  $matched
     * @param  callable(int): bool  $withinRange
     * @return array<string, mixed>|null
     */
    private function rangeStrength(Collection $matched, string $label, callable $withinRange, string $phrase): ?array
    {
        $slice = $matched->filter(fn (array $row): bool => $withinRange($row['distance_ft']));
        $sample = (int) $slice->sum('weight');

        if ($sample < 20) {
            return null;
        }

        $blade = $this->matchedRate($slice, 'blade_percent');
        $mallet = $this->matchedRate($slice, 'mallet_percent');
        $gap = round($mallet - $blade, 1);

        if (abs($gap) < 3.0) {
            return null;
        }

        $winner = $gap > 0 ? Putter::Mallet : Putter::Blade;

        return [
            'putter' => $winner,
            'magnitude' => abs($gap),
            'headline' => $label,
            'detail' => sprintf(
                '%s%% vs %s%% %s — %s points better over %d matched putts.',
                $winner === Putter::Mallet ? $mallet : $blade,
                $winner === Putter::Mallet ? $blade : $mallet,
                $phrase,
                abs($gap),
                $sample,
            ),
        ];
    }

    /**
     * A putter whose misses skew to speed is keeping the ball on line, which is
     * the whole argument for a high-MOI head.
     *
     * @param  array<string, array<string, mixed>>  $headline
     * @return array<string, mixed>|null
     */
    private function lineControlStrength(array $headline): ?array
    {
        $blade = $headline[Putter::Blade->value];
        $mallet = $headline[Putter::Mallet->value];

        if ($blade['line_percent'] + $blade['speed_percent'] <= 0 || $mallet['line_percent'] + $mallet['speed_percent'] <= 0) {
            return null;
        }

        $gap = round($blade['line_percent'] - $mallet['line_percent'], 1);

        if (abs($gap) < 5.0) {
            return null;
        }

        $winner = $gap > 0 ? Putter::Mallet : Putter::Blade;
        $winnerLine = $winner === Putter::Mallet ? $mallet['line_percent'] : $blade['line_percent'];
        $loserLine = $winner === Putter::Mallet ? $blade['line_percent'] : $mallet['line_percent'];

        return [
            'putter' => $winner,
            'magnitude' => abs($gap),
            'headline' => 'Holds the line',
            'detail' => sprintf(
                'Only %s%% of its misses are left or right, against %s%% for the %s. The rest are speed, which is the easier error to fix.',
                $winnerLine,
                $loserLine,
                strtolower($winner->other()->label()),
            ),
        ];
    }

    /**
     * Carpet flatters both putters. The one that gives up least on a real green is
     * the one that actually travels.
     *
     * @param  array<string, array<string, mixed>>  $headline
     * @return array<string, mixed>|null
     */
    private function outdoorStrength(array $headline): ?array
    {
        $drops = [];

        foreach ([Putter::Blade, Putter::Mallet] as $putter) {
            $row = $headline[$putter->value];

            if ($row['inside']['attempts'] < 25 || $row['outside']['attempts'] < 25) {
                return null;
            }

            $drops[$putter->value] = round($row['inside']['make_percent'] - $row['outside']['make_percent'], 1);
        }

        $gap = round($drops[Putter::Blade->value] - $drops[Putter::Mallet->value], 1);

        if (abs($gap) < 4.0) {
            return null;
        }

        $winner = $gap > 0 ? Putter::Mallet : Putter::Blade;

        return [
            'putter' => $winner,
            'magnitude' => abs($gap),
            'headline' => 'Travels to real greens',
            'detail' => sprintf(
                'Moving outside it %s, where the %s %s.',
                $this->describeDrop($drops[$winner->value]),
                strtolower($winner->other()->label()),
                $this->describeDrop($drops[$winner->other()->value]),
            ),
        ];
    }

    /**
     * Outside make rates can beat inside ones, so the phrasing has to survive a
     * negative drop without reading as "drops only -4.9 points".
     */
    private function describeDrop(float $drop): string
    {
        return $drop > 0
            ? sprintf('gives up %s points', $drop)
            : sprintf('gains %s points', abs($drop));
    }

    /**
     * The distance where the make rate crosses 50%, which is the cleanest single
     * answer to how far each putter carries you.
     *
     * Scored in feet, multiplied to sit on a comparable scale to the percentage-point
     * magnitudes the other strengths report.
     *
     * @param  array<string, array<string, mixed>>  $headline
     * @return array<string, mixed>|null
     */
    private function reachStrength(array $headline): ?array
    {
        $blade = $headline[Putter::Blade->value]['fifty_percent_distance'];
        $mallet = $headline[Putter::Mallet->value]['fifty_percent_distance'];

        if ($blade === null || $mallet === null) {
            return null;
        }

        $gap = round($mallet - $blade, 1);

        if (abs($gap) < 1.0) {
            return null;
        }

        $winner = $gap > 0 ? Putter::Mallet : Putter::Blade;

        return [
            'putter' => $winner,
            'magnitude' => abs($gap) * 4,
            'headline' => 'Reaches further',
            'detail' => sprintf(
                'Still a coin flip at %s ft, where the %s is down to 50%% by %s ft.',
                $winner === Putter::Mallet ? $mallet : $blade,
                strtolower($winner->other()->label()),
                $winner === Putter::Mallet ? $blade : $mallet,
            ),
        ];
    }

    /**
     * A lopsided left/right miss is aim or path rather than the read, so the putter
     * that stays balanced is the one not fighting your stroke.
     *
     * @param  array<string, array<string, mixed>>  $headline
     * @return array<string, mixed>|null
     */
    private function aimStrength(array $headline): ?array
    {
        $skews = [];

        foreach ([Putter::Blade, Putter::Mallet] as $putter) {
            $row = $headline[$putter->value];
            $sides = $row['miss_left'] + $row['miss_right'];

            if ($sides < 20) {
                return null;
            }

            $skews[$putter->value] = round(abs($row['miss_left'] - $row['miss_right']) / $sides * 100, 1);
        }

        $gap = round($skews[Putter::Blade->value] - $skews[Putter::Mallet->value], 1);

        if (abs($gap) < 12.0) {
            return null;
        }

        $winner = $gap > 0 ? Putter::Mallet : Putter::Blade;
        $loserRow = $headline[$winner->other()->value];
        $loserSide = $loserRow['miss_left'] > $loserRow['miss_right'] ? 'left' : 'right';

        return [
            'putter' => $winner,
            'magnitude' => abs($gap),
            'headline' => 'Misses evenly',
            'detail' => sprintf(
                'Its line misses split %s%% to one side, against %s%% for the %s, which leaks %s.',
                $skews[$winner->value],
                $skews[$winner->other()->value],
                strtolower($winner->other()->label()),
                $loserSide,
            ),
        ];
    }

    /**
     * One putter's stats, narrowed to this comparison's context if it has one.
     */
    private function scopedStats(Putter $putter): PuttStats
    {
        return $this->stats->forPutter($putter)->inContext($this->context);
    }

    /**
     * @return array{attempts: int, sunk: int, make_percent: float}
     */
    private function contextSummary(PuttStats $scoped, PuttContext $context): array
    {
        $rows = $scoped->byDistance($context);
        $attempts = (int) $rows->sum('attempts');
        $sunk = (int) $rows->sum('sunk');

        return [
            'attempts' => $attempts,
            'sunk' => $sunk,
            'make_percent' => $attempts > 0 ? round($sunk / $attempts * 100, 1) : 0.0,
        ];
    }
}
