<?php

namespace App\Services;

use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Models\Putter;
use App\Models\User;
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

    /** @var array<string, mixed>|null */
    private ?array $matched = null;

    private ?PuttContext $context = null;

    private ?Putter $first = null;

    private ?Putter $second = null;

    public function __construct(
        private PuttStats $stats,
        private PuttingProfile $profile,
        private AdjustedRate $adjusted,
    ) {}

    /**
     * A copy that reads one player's putts. Required before anything is compared.
     */
    public function forUser(User $user): self
    {
        $clone = clone $this;
        $clone->stats = $this->stats->forUser($user);
        $clone->forget();

        return $clone;
    }

    /**
     * The two putters under comparison. Results are keyed by putter id, and every
     * head-to-head figure reads "second minus first", so a positive gap favours the
     * second putter.
     */
    public function between(Putter $first, Putter $second): self
    {
        $clone = clone $this;
        $clone->first = $first;
        $clone->second = $second;
        $clone->forget();

        return $clone;
    }

    /**
     * @return array{0: Putter, 1: Putter}
     */
    public function putters(): array
    {
        if ($this->first === null || $this->second === null) {
            throw new \LogicException('Choose two putters with between() before comparing.');
        }

        return [$this->first, $this->second];
    }

    /**
     * Each putter's radar profile, for overlaying one shape on the other.
     *
     * The two shapes are drawn on one set of spokes, so they must share an axis set.
     * The line axis therefore splits only when both putters have enough classified
     * misses — letting each decide alone would put a hexagon over a pentagon, and a
     * putter with three labelled misses would score a Stroke axis off nearly nothing.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function profiles(): array
    {
        $scoped = [];

        foreach ($this->putters() as $putter) {
            $scoped[$putter->id] = $this->scopedStats($putter);
        }

        $splitLine = collect($scoped)->every(
            fn (PuttStats $stats): bool => $this->profile->canSplitLine($stats),
        );

        return collect($scoped)
            ->map(fn (PuttStats $stats): array => $this->profile->build($stats, $splitLine))
            ->all();
    }

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
        $clone->forget();

        return $clone;
    }

    public function context(): ?PuttContext
    {
        return $this->context;
    }

    /**
     * How each putter holds up when you leave the carpet: inside and outside make
     * rates side by side, plus the points given up moving between them. Keyed by
     * putter id.
     *
     * @param  iterable<int, Putter>  $putters  defaults to the pair under comparison
     * @return array<int, array<string, mixed>>
     */
    public function contextBreakdown(?iterable $putters = null): array
    {
        $breakdown = [];

        foreach ($putters ?? $this->putters() as $putter) {
            // Deliberately not context-scoped: this row spans both sides.
            $scoped = $this->stats->forPutter($putter);
            $inside = $this->contextSummary($scoped, PuttContext::Inside);
            $outside = $this->contextSummary($scoped, PuttContext::Outside);

            $breakdown[$putter->id] = [
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
     * Side-by-side totals for each putter, keyed by putter id.
     *
     * Memoised because verdict() and strengths() both lean on it, and each call
     * costs a handful of aggregate queries per putter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function headline(): array
    {
        if ($this->headline !== null) {
            return $this->headline;
        }

        $headline = [];

        foreach ($this->putters() as $putter) {
            $scoped = $this->scopedStats($putter);
            $dial = $scoped->missDial();
            $split = $scoped->speedVsLine();

            $attempts = array_sum(array_column($dial, 'count'));

            $headline[$putter->id] = [
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

        [$firstPutter, $secondPutter] = $this->putters();
        $first = $this->scopedStats($firstPutter)->byDistance()->keyBy('distance_ft');
        $second = $this->scopedStats($secondPutter)->byDistance()->keyBy('distance_ft');

        return $this->byDistance = $first->keys()
            ->intersect($second->keys())
            ->sort()
            ->values()
            ->map(fn (int $distance): array => [
                'distance_ft' => $distance,
                'first_percent' => $first[$distance]['make_percent'],
                'second_percent' => $second[$distance]['make_percent'],
                'first_attempts' => $first[$distance]['attempts'],
                'second_attempts' => $second[$distance]['attempts'],
                // The smaller of the two counts: neither putter gets credit for reps
                // the other never took at this distance.
                'weight' => min($first[$distance]['attempts'], $second[$distance]['attempts']),
                'gap' => round($second[$distance]['make_percent'] - $first[$distance]['make_percent'], 1),
            ])
            ->filter(fn (array $row): bool => $row['first_attempts'] >= self::MIN_ATTEMPTS_PER_DISTANCE
                && $row['second_attempts'] >= self::MIN_ATTEMPTS_PER_DISTANCE)
            ->values();
    }

    /**
     * Both putters' raw and mix-levelled make rates over the strata they share.
     *
     * Exposed as well as used by the verdict, because seeing the raw figure move
     * when it is levelled is the clearest possible answer to "is this putter
     * actually better, or did it just get the easier putts?".
     *
     * @return array<string, mixed>
     */
    public function matchedRates(): array
    {
        [$first, $second] = $this->putters();

        return $this->matched ??= $this->adjusted->matched([
            'first' => $this->scopedStats($first),
            'second' => $this->scopedStats($second),
        ]);
    }

    /**
     * Which putter to keep in the bag, or an honest refusal to call it.
     *
     * The rates are standardised by AdjustedRate before they are compared, so the
     * two putters are measured over the same mix of putts rather than over whatever
     * each happened to face. Without that, hitting more short putts with one putter
     * is enough to win it the recommendation.
     *
     * The standardised rates are then compared with a two-proportion z-test. That is
     * a Mantel-Haenszel style approximation rather than an exact stratified test —
     * close enough to stop the page recommending a putter on a handful of lucky
     * putts, which is the only job it has here.
     *
     * @return array<string, mixed>
     */
    public function verdict(): array
    {
        [$first, $second] = $this->putters();
        $headline = $this->headline();

        $matched = $this->matchedRates();
        $sample = $matched['sample'];

        $shortfall = $this->shortfall($headline[$first->id]['attempts'], $headline[$second->id]['attempts'], $sample);

        if ($shortfall !== null) {
            return $shortfall;
        }

        $firstRate = $matched['scopes']['first']['adjusted_percent'];
        $secondRate = $matched['scopes']['second']['adjusted_percent'];
        $gap = round($secondRate - $firstRate, 1);
        $leader = $gap >= 0 ? $second : $first;
        $z = $this->zScore($firstRate, $secondRate, $sample);

        if (abs($z) < self::CONFIDENCE_Z) {
            return [
                'state' => 'too_close',
                'putter' => null,
                'first_percent' => $firstRate,
                'second_percent' => $secondRate,
                'gap' => $gap,
                'sample' => $sample,
                'z' => round($z, 2),
                'matched_on' => $matched['dimensions'],
                'message' => sprintf(
                    'Too close to call. Across %d putts matched on %s, %s is ahead by just %s points, which is inside the noise — play whichever you prefer.',
                    $sample,
                    AdjustedRate::describeDimensions($matched['dimensions']),
                    $leader->name,
                    abs($gap),
                ),
            ];
        }

        return [
            'state' => 'recommended',
            'putter' => $leader,
            'first_percent' => $firstRate,
            'second_percent' => $secondRate,
            'gap' => $gap,
            'sample' => $sample,
            'z' => round($z, 2),
            'matched_on' => $matched['dimensions'],
            'message' => sprintf(
                'Play %s. Levelled for %s so both face the same mix, it makes %s%% against %s\'s %s%% — %s points better over %d matched putts, which is more than chance explains.',
                $leader->name,
                AdjustedRate::describeDimensions($matched['dimensions']),
                $leader->is($second) ? $secondRate : $firstRate,
                $this->other($leader)->name,
                $leader->is($second) ? $firstRate : $secondRate,
                abs($gap),
                $sample,
            ),
        ];
    }

    /**
     * What each putter does better than the other, strongest signal first and keyed
     * by putter id. Every entry is gated on its own sample, so a thin dataset
     * yields fewer claims rather than weaker ones.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function strengths(): array
    {
        $matched = $this->byDistance();
        $headline = $this->headline();

        $items = collect([
            $this->rangeStrength($matched, 'Short range', fn (int $d): bool => $d <= 8, 'inside 8 ft'),
            $this->rangeStrength($matched, 'Long range', fn (int $d): bool => $d >= 15, 'from 15 ft and out'),
            $this->lineControlStrength($headline),
            $this->faceControlStrength(),
            $this->outdoorStrength($headline),
            $this->reachStrength($headline),
            $this->aimStrength($headline),
        ])->filter();

        $strengths = [];

        foreach ($this->putters() as $putter) {
            $strengths[$putter->id] = $items
                ->filter(fn (array $item): bool => $item['putter']->is($putter))
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
    private function zScore(float $firstPercent, float $secondPercent, int $sample): float
    {
        if ($sample <= 0) {
            return 0.0;
        }

        $first = $firstPercent / 100;
        $second = $secondPercent / 100;
        $pooled = ($first + $second) / 2;
        $variance = $pooled * (1 - $pooled) * (2 / $sample);

        if ($variance <= 0.0) {
            return 0.0;
        }

        return ($second - $first) / sqrt($variance);
    }

    /**
     * The reason a verdict cannot be given yet, or null when there is enough data.
     *
     * @return array<string, mixed>|null
     */
    private function shortfall(int $firstAttempts, int $secondAttempts, int $sample): ?array
    {
        $thinnest = min($firstAttempts, $secondAttempts);

        if ($thinnest < self::MIN_ATTEMPTS_PER_PUTTER) {
            [$first, $second] = $this->putters();
            $behind = $firstAttempts <= $secondAttempts ? $first : $second;

            return [
                'state' => 'insufficient_data',
                'putter' => null,
                'needed' => self::MIN_ATTEMPTS_PER_PUTTER - $thinnest,
                'sample' => $sample,
                'message' => sprintf(
                    'Not enough data to call it. Log %d more putts with %s and this will start comparing them.',
                    self::MIN_ATTEMPTS_PER_PUTTER - $thinnest,
                    $behind->name,
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

        $first = $this->matchedRate($slice, 'first_percent');
        $second = $this->matchedRate($slice, 'second_percent');
        $gap = round($second - $first, 1);

        if (abs($gap) < 3.0) {
            return null;
        }

        $secondWins = $gap > 0;

        return [
            'putter' => $this->pick($secondWins),
            'magnitude' => abs($gap),
            'headline' => $label,
            'detail' => sprintf(
                '%s%% vs %s%% %s — %s points better over %d matched putts.',
                $secondWins ? $second : $first,
                $secondWins ? $first : $second,
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
     * @param  array<int, array<string, mixed>>  $headline
     * @return array<string, mixed>|null
     */
    private function lineControlStrength(array $headline): ?array
    {
        [$firstPutter, $secondPutter] = $this->putters();
        $first = $headline[$firstPutter->id];
        $second = $headline[$secondPutter->id];

        if ($first['line_percent'] + $first['speed_percent'] <= 0 || $second['line_percent'] + $second['speed_percent'] <= 0) {
            return null;
        }

        $gap = round($first['line_percent'] - $second['line_percent'], 1);

        if (abs($gap) < 5.0) {
            return null;
        }

        $winner = $this->pick($gap > 0);
        $winnerLine = $headline[$winner->id]['line_percent'];
        $loserLine = $headline[$this->other($winner)->id]['line_percent'];

        return [
            'putter' => $winner,
            'magnitude' => abs($gap),
            'headline' => 'Holds the line',
            'detail' => sprintf(
                'Only %s%% of its misses are left or right, against %s%% for %s. The rest are speed, which is the easier error to fix.',
                $winnerLine,
                $loserLine,
                $this->other($winner)->name,
            ),
        ];
    }

    /**
     * The sharpest test of whether a head shape is earning its keep. A high-MOI
     * mallet should cut pushes and pulls, because it resists twisting on an
     * off-centre strike; it can do nothing about a misread green. So the comparison
     * that matters is stroke misses per putt, not line misses per putt.
     *
     * @return array<string, mixed>|null
     */
    private function faceControlStrength(): ?array
    {
        $rates = [];

        foreach ($this->putters() as $putter) {
            $scoped = $this->scopedStats($putter);
            $causes = $scoped->lineMissCauses();

            if ($causes['classified'] < 15) {
                return null;
            }

            $attempts = (int) $scoped->byDistance()->sum('attempts');

            if ($attempts <= 0) {
                return null;
            }

            // Extrapolate the classified sample across every line miss, as the radar does.
            $lineMisses = $scoped->speedVsLine()['line'];
            $rates[$putter->id] = round(
                $lineMisses * ($causes['stroke_percent'] / 100) / $attempts * 100,
                1,
            );
        }

        [$first, $second] = $this->putters();
        $gap = round($rates[$first->id] - $rates[$second->id], 1);

        if (abs($gap) < 2.0) {
            return null;
        }

        $winner = $this->pick($gap > 0);

        return [
            'putter' => $winner,
            'magnitude' => abs($gap) * 3,
            'headline' => 'Squares the face',
            'detail' => sprintf(
                'Only %s%% of putts are pushed or pulled with it, against %s%% for %s. That gap is the head shape doing its job, not the read.',
                $rates[$winner->id],
                $rates[$this->other($winner)->id],
                $this->other($winner)->name,
            ),
        ];
    }

    /**
     * Carpet flatters both putters. The one that gives up least on a real green is
     * the one that actually travels.
     *
     * @param  array<int, array<string, mixed>>  $headline
     * @return array<string, mixed>|null
     */
    private function outdoorStrength(array $headline): ?array
    {
        $drops = [];

        foreach ($this->putters() as $putter) {
            $row = $headline[$putter->id];

            if ($row['inside']['attempts'] < 25 || $row['outside']['attempts'] < 25) {
                return null;
            }

            $drops[$putter->id] = round($row['inside']['make_percent'] - $row['outside']['make_percent'], 1);
        }

        [$first, $second] = $this->putters();
        $gap = round($drops[$first->id] - $drops[$second->id], 1);

        if (abs($gap) < 4.0) {
            return null;
        }

        $winner = $this->pick($gap > 0);

        return [
            'putter' => $winner,
            'magnitude' => abs($gap),
            'headline' => 'Travels to real greens',
            'detail' => sprintf(
                'Moving outside it %s, where %s %s.',
                $this->describeDrop($drops[$winner->id]),
                $this->other($winner)->name,
                $this->describeDrop($drops[$this->other($winner)->id]),
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
     * @param  array<int, array<string, mixed>>  $headline
     * @return array<string, mixed>|null
     */
    private function reachStrength(array $headline): ?array
    {
        [$firstPutter, $secondPutter] = $this->putters();
        $first = $headline[$firstPutter->id]['fifty_percent_distance'];
        $second = $headline[$secondPutter->id]['fifty_percent_distance'];

        if ($first === null || $second === null) {
            return null;
        }

        $gap = round($second - $first, 1);

        if (abs($gap) < 1.0) {
            return null;
        }

        $secondWins = $gap > 0;
        $winner = $this->pick($secondWins);

        return [
            'putter' => $winner,
            'magnitude' => abs($gap) * 4,
            'headline' => 'Reaches further',
            'detail' => sprintf(
                'Still a coin flip at %s ft, where %s is down to 50%% by %s ft.',
                $secondWins ? $second : $first,
                $this->other($winner)->name,
                $secondWins ? $first : $second,
            ),
        ];
    }

    /**
     * A lopsided left/right miss is aim or path rather than the read, so the putter
     * that stays balanced is the one not fighting your stroke.
     *
     * @param  array<int, array<string, mixed>>  $headline
     * @return array<string, mixed>|null
     */
    private function aimStrength(array $headline): ?array
    {
        $skews = [];

        foreach ($this->putters() as $putter) {
            $row = $headline[$putter->id];
            $sides = $row['miss_left'] + $row['miss_right'];

            if ($sides < 20) {
                return null;
            }

            $skews[$putter->id] = round(abs($row['miss_left'] - $row['miss_right']) / $sides * 100, 1);
        }

        [$first, $second] = $this->putters();
        $gap = round($skews[$first->id] - $skews[$second->id], 1);

        if (abs($gap) < 12.0) {
            return null;
        }

        $winner = $this->pick($gap > 0);
        $loserRow = $headline[$this->other($winner)->id];
        $loserSide = $loserRow['miss_left'] > $loserRow['miss_right'] ? 'left' : 'right';

        return [
            'putter' => $winner,
            'magnitude' => abs($gap),
            'headline' => 'Misses evenly',
            'detail' => sprintf(
                'Its line misses split %s%% to one side, against %s%% for %s, which leaks %s.',
                $skews[$winner->id],
                $skews[$this->other($winner)->id],
                $this->other($winner)->name,
                $loserSide,
            ),
        ];
    }

    /**
     * The second putter when true, the first when false — matching the sign
     * convention of every gap, where positive favours the second.
     */
    private function pick(bool $second): Putter
    {
        return $this->putters()[$second ? 1 : 0];
    }

    private function other(Putter $putter): Putter
    {
        [$first, $second] = $this->putters();

        return $putter->is($first) ? $second : $first;
    }

    private function forget(): void
    {
        $this->headline = null;
        $this->byDistance = null;
        $this->matched = null;
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
