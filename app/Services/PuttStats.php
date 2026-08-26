<?php

namespace App\Services;

use App\Enums\LineMissCause;
use App\Enums\PuttContext;
use App\Enums\Putter;
use App\Enums\PuttResult;
use App\Models\Challenge;
use App\Models\Putt;
use App\Models\PuttingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PuttStats
{
    private ?Putter $putter = null;

    private ?PuttContext $context = null;

    private ?int $sessionId = null;

    /**
     * A copy of this service that only ever sees one putter's putts. Passing null
     * returns an unscoped copy that pools both.
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

    public function putter(): ?Putter
    {
        return $this->putter;
    }

    public function context(): ?PuttContext
    {
        return $this->context;
    }

    /**
     * Progress against the challenge targets, plus the pace needed to finish on time.
     *
     * Deliberately unscoped: the challenge is a volume goal, so every putt counts
     * towards it no matter which putter hit it. Calling this on a scoped copy still
     * returns combined figures.
     *
     * @return array<string, int|float>
     */
    public function progress(Challenge $challenge): array
    {
        $totals = $this->conditionalCounts(Putt::query());

        $total = (int) $totals['attempts'];
        $outside = (int) $totals['outside'];
        $daysRemaining = $challenge->daysRemaining();

        $remaining = max(0, $challenge->target_total - $total);
        $outsideRemaining = max(0, $challenge->target_outside_min - $outside);

        return [
            'total' => $total,
            'inside' => $total - $outside,
            'outside' => $outside,
            'sunk' => (int) $totals['sunk'],
            'make_percent' => $this->percent((int) $totals['sunk'], $total),
            'target_total' => $challenge->target_total,
            'target_outside_min' => $challenge->target_outside_min,
            'remaining' => $remaining,
            'outside_remaining' => $outsideRemaining,
            'days_remaining' => $daysRemaining,
            'per_day_needed' => $daysRemaining > 0 ? (int) ceil($remaining / $daysRemaining) : $remaining,
            'outside_per_day_needed' => $daysRemaining > 0 ? (int) ceil($outsideRemaining / $daysRemaining) : $outsideRemaining,
            'percent_complete' => $this->percent($total, $challenge->target_total),
        ];
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
     * Daily volume against the flat pace line the challenge requires.
     *
     * Unscoped for the same reason as progress(): the pace line tracks the challenge,
     * which counts every putt regardless of putter.
     *
     * Grouped in PHP rather than SQL so the query stays portable between
     * SQLite locally and Postgres in production.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function dailyVolume(Challenge $challenge): Collection
    {
        $perDay = Putt::query()
            ->selectRaw('hit_at, context')
            ->get()
            ->groupBy(fn (Putt $putt): string => $putt->hit_at->toDateString());

        $requiredPerDay = $challenge->target_total / max(1, $challenge->totalDays());
        $cumulative = 0;
        $days = collect();
        $cursor = $challenge->start_date->copy();
        $today = Carbon::today();
        $dayNumber = 0;

        while ($cursor->lessThanOrEqualTo($challenge->end_date)) {
            $key = $cursor->toDateString();
            $dayNumber++;
            $putts = $perDay->get($key);
            $count = $putts?->count() ?? 0;
            $cumulative += $count;

            $days->push([
                'date' => $key,
                'label' => $cursor->format('M j'),
                'count' => $count,
                'outside' => $putts?->where('context', PuttContext::Outside)->count() ?? 0,
                'cumulative' => $cursor->greaterThan($today) ? null : $cumulative,
                'target_cumulative' => (int) round($requiredPerDay * $dayNumber),
            ]);

            $cursor->addDay();
        }

        return $days;
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

        $fifty = $this->fiftyPercentDistance();

        if ($fifty !== null) {
            $insights[] = sprintf('Your make rate crosses 50%% at about %s ft.', $fifty);
        }

        return $insights === [] ? ['No strong patterns yet — keep logging.'] : $insights;
    }

    /**
     * How the current scope reads in a sentence: "with the mallet outside",
     * "outside", "with the blade", or an empty string when nothing is scoped.
     */
    private function scopeSuffix(): string
    {
        $parts = array_filter([
            $this->putter !== null ? sprintf('with the %s', strtolower($this->putter->label())) : null,
            $this->context !== null ? strtolower($this->context->label()) : null,
        ]);

        return implode(' ', $parts);
    }

    /**
     * Every performance query starts here, so a scoped copy can never leak another
     * putter's or another session's putts into a stat.
     *
     * @return Builder<Putt>
     */
    private function baseQuery(): Builder
    {
        return Putt::query()
            ->when($this->putter, fn (Builder $query, Putter $putter): Builder => $query->where('putter', $putter))
            ->when($this->context, fn (Builder $query, PuttContext $context): Builder => $query->where('context', $context))
            ->when($this->sessionId, fn (Builder $query, int $id): Builder => $query->where('putting_session_id', $id));
    }

    /**
     * @param  Builder<Putt>  $query
     * @return array<string, int>
     */
    private function conditionalCounts(Builder $query): array
    {
        $row = $query
            ->selectRaw('count(*) as attempts')
            ->selectRaw($this->countCase(PuttResult::Sunk, 'sunk'))
            ->selectRaw(sprintf(
                'sum(case when context = %s then 1 else 0 end) as outside',
                $this->quote(PuttContext::Outside->value),
            ))
            ->first();

        return [
            'attempts' => (int) $row->attempts,
            'sunk' => (int) $row->sunk,
            'outside' => (int) $row->outside,
        ];
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
