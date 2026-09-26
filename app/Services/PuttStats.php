<?php

namespace App\Services;

use App\Enums\BreakSide;
use App\Enums\ClockPosition;
use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\PuttResult;
use App\Enums\PuttSlope;
use App\Models\Putt;
use App\Models\Putter;
use App\Models\PuttingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class PuttStats
{
    /**
     * A position rollup needs this many putts before it is allowed to make a claim.
     */
    private const MIN_POSITION_SAMPLE = 20;

    /**
     * And the two sides have to differ by this many points, or the "gap" is noise.
     */
    private const MIN_POSITION_GAP = 8.0;

    private ?int $userId = null;

    private ?Putter $putter = null;

    private ?PuttContext $context = null;

    private ?int $sessionId = null;

    private ?ClockPosition $position = null;

    private ?PuttSlope $slope = null;

    /**
     * A copy of this service that sees one player's putts. Every query needs one:
     * an unscoped copy refuses to run rather than pool everybody's practice.
     */
    public function forUser(User $user): self
    {
        $clone = clone $this;
        $clone->userId = $user->id;

        return $clone;
    }

    /**
     * A copy of this service that only ever sees one putter's putts. Passing null
     * returns an unscoped copy that pools every putter.
     */
    public function forPutter(?Putter $putter): self
    {
        $clone = clone $this;
        $clone->putter = $putter;

        return $clone;
    }

    /**
     * A copy of this service narrowed to inside or outside putts. Passing null
     * returns an unscoped copy that pools both.
     *
     * Note that insideVsOutside() returns nothing on a context-scoped copy — it has
     * only one side left to compare. Callers that want that comparison should ask an
     * unscoped copy for it.
     */
    public function inContext(?PuttContext $context): self
    {
        $clone = clone $this;
        $clone->context = $context;

        return $clone;
    }

    /**
     * A copy of this service narrowed to a single practice session. A session only
     * ever holds one putter and one context, so there is no need to scope by putter
     * as well — doing so would be redundant, never contradictory.
     */
    public function forSession(?PuttingSession $session): self
    {
        $clone = clone $this;
        $clone->sessionId = $session?->id;

        return $clone;
    }

    /**
     * A copy narrowed to one position on the clock. Passing null returns an
     * unscoped copy.
     *
     * Putts logged before the ring existed carry no position, so a scoped copy
     * never sees them. That is deliberate: they are unclassified, not flat.
     */
    public function atPosition(?ClockPosition $position): self
    {
        $clone = clone $this;
        $clone->position = $position;

        return $clone;
    }

    /**
     * A copy narrowed to uphill, downhill or flat putts. Coarser than
     * atPosition(), and it reaches the older putts that were slope-tagged by hand
     * before the ring replaced that input.
     */
    public function onSlope(?PuttSlope $slope): self
    {
        $clone = clone $this;
        $clone->slope = $slope;

        return $clone;
    }

    public function putter(): ?Putter
    {
        return $this->putter;
    }

    public function context(): ?PuttContext
    {
        return $this->context;
    }

    /**
     * Counts for each zone of the dial, as raw counts and as a share of all putts.
     *
     * @return array<string, array{label: string, count: int, percent: float}>
     */
    public function missDial(): array
    {
        $counts = $this->baseQuery()
            ->selectRaw('result, count(*) as total')
            ->groupBy('result')
            ->pluck('total', 'result');

        $attempts = (int) $counts->sum();

        $dial = [];

        foreach (PuttResult::cases() as $result) {
            $count = (int) ($counts[$result->value] ?? 0);

            $dial[$result->value] = [
                'label' => $result->label(),
                'count' => $count,
                'percent' => $this->percent($count, $attempts),
            ];
        }

        return $dial;
    }

    /**
     * Short and long misses are speed errors; left and right are line errors.
     * This split is the most actionable read on where practice time should go.
     *
     * @return array{speed: int, line: int, lip_out: int, speed_percent: float, line_percent: float}
     */
    public function speedVsLine(?PuttContext $context = null): array
    {
        $query = $this->baseQuery()->where('result', '!=', PuttResult::Sunk);

        if ($context !== null) {
            $query->where('context', $context);
        }

        $counts = $query
            ->selectRaw('result, count(*) as total')
            ->groupBy('result')
            ->pluck('total', 'result');

        $speed = (int) ($counts[PuttResult::MissShort->value] ?? 0)
            + (int) ($counts[PuttResult::MissLong->value] ?? 0);
        $line = (int) ($counts[PuttResult::MissLeft->value] ?? 0)
            + (int) ($counts[PuttResult::MissRight->value] ?? 0);
        $lipOut = (int) ($counts[PuttResult::LipOut->value] ?? 0);

        $classified = $speed + $line;

        return [
            'speed' => $speed,
            'line' => $line,
            'lip_out' => $lipOut,
            'speed_percent' => $this->percent($speed, $classified),
            'line_percent' => $this->percent($line, $classified),
        ];
    }

    /**
     * Left and right misses split by cause, plus how that split changes between the
     * carpet and a real green. Read errors should spike outside, where the ground
     * actually breaks; stroke errors should not care either way.
     *
     * Only counts classified misses — anything logged on the simple dial is unknown
     * rather than zero, so it is reported separately instead of skewing the split.
     *
     * @return array<string, mixed>
     */
    public function lineMissCauses(): array
    {
        $rows = $this->baseQuery()
            ->whereIn('result', [PuttResult::MissLeft, PuttResult::MissRight])
            ->selectRaw('miss_cause, context, count(*) as total')
            ->groupBy('miss_cause', 'context')
            ->get();

        $countFor = fn (?LineMissCause $cause, ?PuttContext $context): int => (int) $rows
            ->when($context !== null, fn ($all) => $all->where('context', $context->value))
            ->where('miss_cause', $cause?->value)
            ->sum('total');

        $stroke = $countFor(LineMissCause::Stroke, null);
        $read = $countFor(LineMissCause::Read, null);
        $classified = $stroke + $read;

        $byContext = [];

        foreach (PuttContext::cases() as $context) {
            $contextStroke = $countFor(LineMissCause::Stroke, $context);
            $contextRead = $countFor(LineMissCause::Read, $context);
            $contextTotal = $contextStroke + $contextRead;

            $byContext[$context->value] = [
                'stroke' => $contextStroke,
                'read' => $contextRead,
                'classified' => $contextTotal,
                'read_percent' => $this->percent($contextRead, $contextTotal),
            ];
        }

        return [
            'stroke' => $stroke,
            'read' => $read,
            'classified' => $classified,
            'unclassified' => $countFor(null, null),
            'stroke_percent' => $this->percent($stroke, $classified),
            'read_percent' => $this->percent($read, $classified),
            'by_context' => $byContext,
        ];
    }

    /**
     * Raw attempt and make counts split by every dimension the adjusted rate can
     * stratify on, for AdjustedRate to bucket in PHP.
     *
     * Grouped by exact distance rather than by band, so the caller decides how
     * coarsely to bucket without needing a second query. Grouping happens in PHP
     * downstream so the query stays portable between SQLite and Postgres.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function cellCounts(): Collection
    {
        return $this->baseQuery()
            ->selectRaw('distance_ft, context, slope')
            ->selectRaw($this->countCase(PuttResult::Sunk, 'sunk'))
            ->selectRaw('count(*) as attempts')
            ->groupBy('distance_ft', 'context', 'slope')
            ->get()
            ->map(fn ($row): array => [
                'distance_ft' => (int) $row->distance_ft,
                'context' => $row->context instanceof PuttContext ? $row->context->value : (string) $row->context,
                'slope' => $row->slope instanceof PuttSlope ? $row->slope->value : ($row->slope === null ? null : (string) $row->slope),
                'attempts' => (int) $row->attempts,
                'sunk' => (int) $row->sunk,
            ]);
    }

    /**
     * Make rate at each position around the hole, plus the rollups that pool the
     * mirrored halves of the clock back together.
     *
     * Putts logged before the ring existed are counted as unclassified rather than
     * dropped, so a thin sample is visible as a thin sample instead of looking like
     * a position nobody ever putts from.
     *
     * @return array<string, mixed>
     */
    public function byClockPosition(): array
    {
        $rows = $this->baseQuery()
            ->selectRaw('clock_position')
            ->selectRaw($this->countCase(PuttResult::Sunk, 'sunk'))
            ->selectRaw('count(*) as attempts')
            ->groupBy('clock_position')
            ->get();

        $unclassified = (int) $rows->whereNull('clock_position')->sum('attempts');
        $positions = [];

        foreach (ClockPosition::cases() as $position) {
            $row = $rows->firstWhere('clock_position', $position->value);
            $attempts = (int) ($row->attempts ?? 0);
            $sunk = (int) ($row->sunk ?? 0);

            $positions[$position->value] = [
                'position' => $position,
                'label' => $position->label(),
                'clock_label' => $position->clockLabel(),
                'attempts' => $attempts,
                'sunk' => $sunk,
                'make_percent' => $this->percent($sunk, $attempts),
            ];
        }

        $classified = array_sum(array_column($positions, 'attempts'));

        return [
            'positions' => $positions,
            'bands' => $this->rollUp($positions, fn (ClockPosition $p): string => $p->difficultyBand(), fn (ClockPosition $p): string => $p->bandLabel()),
            'slopes' => $this->rollUp($positions, fn (ClockPosition $p): string => $p->slope()->value, fn (ClockPosition $p): string => $p->slope()->label()),
            'breaks' => $this->rollUp($positions, fn (ClockPosition $p): string => $p->breakSide()->value, fn (ClockPosition $p): string => $p->breakSide()->shortLabel()),
            'classified' => $classified,
            'unclassified' => $unclassified,
        ];
    }

    /**
     * Pool the per-position counts into a coarser grouping, keeping the make rate
     * a true rate over the pooled attempts rather than an average of averages.
     *
     * @param  array<string, array<string, mixed>>  $positions
     * @param  callable(ClockPosition): string  $key
     * @param  callable(ClockPosition): string  $label
     * @return array<string, array<string, mixed>>
     */
    private function rollUp(array $positions, callable $key, callable $label): array
    {
        $grouped = [];

        foreach ($positions as $row) {
            $group = $key($row['position']);

            $grouped[$group] ??= ['label' => $label($row['position']), 'attempts' => 0, 'sunk' => 0];
            $grouped[$group]['attempts'] += $row['attempts'];
            $grouped[$group]['sunk'] += $row['sunk'];
        }

        foreach ($grouped as $group => $row) {
            $grouped[$group]['make_percent'] = $this->percent($row['sunk'], $row['attempts']);
        }

        return $grouped;
    }

    /**
     * Make percentage and miss shape at every distance played.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function byDistance(?PuttContext $context = null): Collection
    {
        $query = $this->baseQuery();

        if ($context !== null) {
            $query->where('context', $context);
        }

        return $query
            ->selectRaw('distance_ft')
            ->selectRaw($this->countCase(PuttResult::Sunk, 'sunk'))
            ->selectRaw($this->countCase(PuttResult::MissShort, 'miss_short'))
            ->selectRaw($this->countCase(PuttResult::MissLong, 'miss_long'))
            ->selectRaw($this->countCase(PuttResult::MissLeft, 'miss_left'))
            ->selectRaw($this->countCase(PuttResult::MissRight, 'miss_right'))
            ->selectRaw($this->countCase(PuttResult::LipOut, 'lip_out'))
            ->selectRaw('count(*) as attempts')
            ->groupBy('distance_ft')
            ->orderBy('distance_ft')
            ->get()
            ->map(function ($row): array {
                $attempts = (int) $row->attempts;
                $short = (int) $row->miss_short;
                $long = (int) $row->miss_long;

                return [
                    'distance_ft' => (int) $row->distance_ft,
                    'attempts' => $attempts,
                    'sunk' => (int) $row->sunk,
                    'make_percent' => $this->percent((int) $row->sunk, $attempts),
                    'short' => $short,
                    'long' => $long,
                    'left' => (int) $row->miss_left,
                    'right' => (int) $row->miss_right,
                    'lip_out' => (int) $row->lip_out,
                    'speed_bias' => $this->percent($long, $short + $long) - $this->percent($short, $short + $long),
                ];
            });
    }

    /**
     * Make percentage inside versus outside at the distances played in both.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function insideVsOutside(): Collection
    {
        $inside = $this->byDistance(PuttContext::Inside)->keyBy('distance_ft');
        $outside = $this->byDistance(PuttContext::Outside)->keyBy('distance_ft');

        return $inside->keys()
            ->intersect($outside->keys())
            ->sort()
            ->values()
            ->map(fn (int $distance): array => [
                'distance_ft' => $distance,
                'inside_percent' => $inside[$distance]['make_percent'],
                'outside_percent' => $outside[$distance]['make_percent'],
                'inside_attempts' => $inside[$distance]['attempts'],
                'outside_attempts' => $outside[$distance]['attempts'],
                'gap' => round($inside[$distance]['make_percent'] - $outside[$distance]['make_percent'], 1),
            ]);
    }

    /**
     * The distance where the make rate crosses 50%, linearly interpolated between
     * the bracketing distances. Null until there is data on both sides of the line.
     */
    public function fiftyPercentDistance(?PuttContext $context = null): ?float
    {
        $rows = $this->byDistance($context)
            ->filter(fn (array $row): bool => $row['attempts'] >= 5)
            ->values();

        for ($i = 0; $i < $rows->count() - 1; $i++) {
            $near = $rows[$i];
            $far = $rows[$i + 1];

            if ($near['make_percent'] >= 50.0 && $far['make_percent'] < 50.0) {
                $span = $near['make_percent'] - $far['make_percent'];

                if ($span <= 0.0) {
                    return (float) $near['distance_ft'];
                }

                $ratio = ($near['make_percent'] - 50.0) / $span;

                return round($near['distance_ft'] + $ratio * ($far['distance_ft'] - $near['distance_ft']), 1);
            }
        }

        return null;
    }

    /**
     * Plain-language readings of the data, strongest signal first.
     *
     * @return array<int, string>
     */
    public function insights(): array
    {
        $insights = [];
        $overall = $this->speedVsLine();
        $attempts = $this->baseQuery()->count();

        if ($attempts < 25) {
            $scope = $this->scopeSuffix();

            return [$scope === ''
                ? 'Log around 25 putts to start seeing patterns here.'
                : sprintf('Log around 25 putts %s to start seeing patterns here.', $scope)];
        }

        if ($overall['speed'] + $overall['line'] > 0) {
            $dominant = $overall['speed_percent'] >= $overall['line_percent'] ? 'speed' : 'line';
            $share = max($overall['speed_percent'], $overall['line_percent']);
            $scope = $this->scopeSuffix();
            $prefix = $scope === '' ? '' : ucfirst($scope).', ';

            $insights[] = $dominant === 'speed'
                ? sprintf('%s%s%% of your misses are short or long — this is distance control, not aim.', $prefix, $share)
                : sprintf('%s%s%% of your misses are left or right — this is read and aim, not speed.', $prefix, $share);
        }

        $dial = $this->missDial();
        $short = $dial[PuttResult::MissShort->value]['count'];
        $long = $dial[PuttResult::MissLong->value]['count'];

        if ($short + $long >= 10 && $short >= $long * 2) {
            $insights[] = 'You leave far more putts short than long. Nothing goes in short.';
        } elseif ($short + $long >= 10 && $long >= $short * 2) {
            $insights[] = 'You run more putts past than leave short — aggressive speed is costing you comebackers.';
        }

        $left = $dial[PuttResult::MissLeft->value]['count'];
        $right = $dial[PuttResult::MissRight->value]['count'];

        if ($left + $right >= 10 && abs($left - $right) / max(1, $left + $right) >= 0.3) {
            $side = $left > $right ? 'left' : 'right';
            $insights[] = sprintf('Your line misses skew %s. A consistent one-sided miss is usually aim or path, not the read.', $side);
        }

        $causes = $this->lineMissCauses();

        if ($causes['classified'] >= 15) {
            $dominant = $causes['stroke'] >= $causes['read'] ? LineMissCause::Stroke : LineMissCause::Read;

            $insights[] = sprintf(
                '%s%% of your classified line misses are %s. That means %s.',
                max($causes['stroke_percent'], $causes['read_percent']),
                $dominant === LineMissCause::Stroke ? 'pushes or pulls' : 'misread breaks',
                $dominant->coaching(),
            );

            $inside = $causes['by_context'][PuttContext::Inside->value];
            $outside = $causes['by_context'][PuttContext::Outside->value];

            if ($inside['classified'] >= 8 && $outside['classified'] >= 8) {
                $swing = round($outside['read_percent'] - $inside['read_percent'], 1);

                $insights[] = $swing >= 15.0
                    ? sprintf('Misreads jump from %s%% of your line misses inside to %s%% outside — real greens are exposing the read, not the stroke.', $inside['read_percent'], $outside['read_percent'])
                    : sprintf('Misreads barely move between inside (%s%%) and outside (%s%%), so the greens are not the problem — the stroke travels with you.', $inside['read_percent'], $outside['read_percent']);
            }
        }

        $gaps = $this->insideVsOutside()
            ->filter(fn (array $row): bool => $row['inside_attempts'] >= 5 && $row['outside_attempts'] >= 5)
            ->sortByDesc('gap')
            ->first();

        if ($gaps !== null && $gaps['gap'] >= 15.0) {
            $insights[] = sprintf(
                'At %d ft you make %s%% inside but only %s%% outside — the carpet is flattering you.',
                $gaps['distance_ft'],
                $gaps['inside_percent'],
                $gaps['outside_percent'],
            );
        }

        foreach ($this->positionInsights() as $insight) {
            $insights[] = $insight;
        }

        $fifty = $this->fiftyPercentDistance();

        if ($fifty !== null) {
            $insights[] = sprintf('Your make rate crosses 50%% at about %s ft.', $fifty);
        }

        return $insights === [] ? ['No strong patterns yet — keep logging.'] : $insights;
    }

    /**
     * What position around the hole is telling you.
     *
     * The question worth answering is which of two very different problems you
     * have. Downhill putts being hard is a speed problem; one break direction being
     * hard is a read or aim problem. They need opposite practice, and pooling the
     * clock into a single make rate hides which one you are looking at — the two
     * downhill-sidehill positions sit on opposite break directions, so a player
     * weak at both is weak at the slope, not at reading one way.
     *
     * @return array<int, string>
     */
    private function positionInsights(): array
    {
        $positions = $this->byClockPosition();

        if ($positions['classified'] < self::MIN_POSITION_SAMPLE) {
            return [];
        }

        $insights = [];

        // Flat and straight putts are overwhelmingly the indoor mat, so leaving them
        // in would compare carpet against real greens and call the result a slope
        // effect. Uphill against downhill, and one break direction against the other,
        // are the only comparisons where the ground is genuinely alike.
        $slopeGap = $this->widestGap(Arr::except($positions['slopes'], [PuttSlope::Flat->value]));
        $breakGap = $this->widestGap(Arr::except($positions['breaks'], [BreakSide::Straight->value]));

        if ($slopeGap !== null) {
            $insights[] = sprintf(
                'You make %s%% on %s putts against %s%% on %s — a %s point swing from the slope alone.',
                $slopeGap['best']['make_percent'],
                strtolower($slopeGap['best']['label']),
                $slopeGap['worst']['make_percent'],
                strtolower($slopeGap['worst']['label']),
                $slopeGap['gap'],
            );
        }

        if ($breakGap !== null) {
            $insights[] = sprintf(
                'Putts %s go in %s%% of the time against %s%% %s. Same slope either way, so a gap that size is aim or read, not speed.',
                $breakGap['worst']['label'],
                $breakGap['worst']['make_percent'],
                $breakGap['best']['make_percent'],
                $breakGap['best']['label'],
            );
        }

        // The disambiguation the clock exists to provide.
        if ($slopeGap !== null && $breakGap === null) {
            $insights[] = 'Your two break directions hold up about equally, so it is the slope beating you rather than the read. Work on speed control downhill before you touch green reading.';
        } elseif ($slopeGap === null && $breakGap !== null) {
            $insights[] = 'Uphill and downhill hold up about equally, so this is not a speed problem — one break direction is simply reading worse than the other.';
        }

        $band = collect($positions['bands'])
            ->filter(fn (array $row): bool => $row['attempts'] >= self::MIN_POSITION_SAMPLE)
            ->sortBy('make_percent')
            ->first();

        if ($band !== null && $slopeGap === null && $breakGap === null) {
            $insights[] = sprintf(
                'Your weakest ground is %s at %s%% over %d putts.',
                strtolower($band['label']),
                $band['make_percent'],
                $band['attempts'],
            );
        }

        return $insights;
    }

    /**
     * The best and worst of a rollup, but only when both are well enough sampled
     * and far enough apart to be worth a sentence.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function widestGap(array $rows): ?array
    {
        $usable = collect($rows)
            ->filter(fn (array $row): bool => $row['attempts'] >= self::MIN_POSITION_SAMPLE)
            ->sortByDesc('make_percent')
            ->values();

        if ($usable->count() < 2) {
            return null;
        }

        $best = $usable->first();
        $worst = $usable->last();
        $gap = round($best['make_percent'] - $worst['make_percent'], 1);

        return $gap >= self::MIN_POSITION_GAP
            ? ['best' => $best, 'worst' => $worst, 'gap' => $gap]
            : null;
    }

    /**
     * How the current scope reads in a sentence: "with Spider X outside",
     * "outside", "with Spider X", or an empty string when nothing is scoped.
     */
    private function scopeSuffix(): string
    {
        $parts = array_filter([
            $this->putter !== null ? sprintf('with %s', $this->putter->name) : null,
            $this->context !== null ? strtolower($this->context->label()) : null,
        ]);

        return implode(' ', $parts);
    }

    /**
     * Every performance query starts here, so a scoped copy can never leak another
     * player's, putter's or session's putts into a stat.
     *
     * @return Builder<Putt>
     */
    private function baseQuery(): Builder
    {
        if ($this->userId === null) {
            throw new \LogicException('PuttStats must be scoped with forUser() before it can query.');
        }

        return Putt::query()
            ->where('user_id', $this->userId)
            ->when($this->putter, fn (Builder $query, Putter $putter): Builder => $query->where('putter_id', $putter->id))
            ->when($this->context, fn (Builder $query, PuttContext $context): Builder => $query->where('context', $context))
            ->when($this->sessionId, fn (Builder $query, int $id): Builder => $query->where('putting_session_id', $id))
            ->when($this->position, fn (Builder $query, ClockPosition $position): Builder => $query->where('clock_position', $position))
            ->when($this->slope, fn (Builder $query, PuttSlope $slope): Builder => $query->where('slope', $slope));
    }

    private function countCase(PuttResult $result, string $alias): string
    {
        return sprintf(
            'sum(case when result = %s then 1 else 0 end) as %s',
            $this->quote($result->value),
            $alias,
        );
    }

    /**
     * Enum values are developer-controlled, but quoting keeps the raw SQL safe by construction.
     */
    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function percent(int $part, int $whole): float
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0.0;
    }
}
