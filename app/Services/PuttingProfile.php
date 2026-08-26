<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Five scores describing the shape of a putting stroke, for the radar chart.
 *
 * Raw make rates make a hopeless radar chart: everyone is near 100% from 3 ft and
 * near 0% from 30 ft, so every player draws the same lopsided shape. Each axis is
 * therefore scored against a reference level, which puts a competent putter near
 * BASELINE_SCORE on every axis and leaves the shape to show where you actually
 * differ from that.
 */
class PuttingProfile
{
    /**
     * Hitting the reference level on an axis scores this, leaving headroom above so
     * being genuinely good at something still reads on the chart.
     */
    public const BASELINE_SCORE = 70;

    /**
     * An axis stays unscored below this many attempts, so an empty distance band
     * reads as "no data" rather than "terrible".
     */
    public const MIN_ATTEMPTS = 10;

    /**
     * @var array<string, array{label: string, note: string, min: int, max: int}>
     */
    private const BANDS = [
        'short' => ['label' => 'Short', 'note' => 'inside 6 ft', 'min' => 1, 'max' => 6],
        'mid' => ['label' => 'Mid', 'note' => '7 to 14 ft', 'min' => 7, 'max' => 14],
        'lag' => ['label' => 'Lag', 'note' => '15 ft and out', 'min' => 15, 'max' => 999],
    ];

    /**
     * The reference putter is a coin flip from this distance. Raise it if the chart
     * flatters you across all three distance bands, lower it if it punishes you.
     */
    private const REFERENCE_HALF_DISTANCE = 7.5;

    /**
     * How fast the reference make rate falls away. Make rate follows a curve that is
     * steep up close and flattens out long, not a straight line.
     */
    private const REFERENCE_STEEPNESS = 2.0;

    /**
     * Share of all putts that a competent putter loses to each error type. Lower is
     * better on these two axes, so they are scored the other way up.
     */
    private const BASELINE_SPEED_ERROR = 0.30;

    private const BASELINE_LINE_ERROR = 0.18;

    /**
     * The line reference split into its two causes, for when there is enough
     * classified data to draw them as separate axes.
     */
    private const BASELINE_STROKE_ERROR = 0.11;

    private const BASELINE_READ_ERROR = 0.07;

    /**
     * Guards the divide when someone has genuinely made everything.
     */
    private const ERROR_FLOOR = 0.02;

    /**
     * Ordered clockwise from the top of the chart: the three distance bands sit
     * together, then the error types.
     *
     * Pass $splitLine to force the line axis to split, or not, instead of letting
     * this scope decide for itself. Overlaid shapes have to share an axis set, so
     * the caller drawing them together makes that call once for all of them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function build(PuttStats $stats, ?bool $splitLine = null): array
    {
        $rows = $stats->byDistance();
        $attempts = (int) $rows->sum('attempts');
        $split = $stats->speedVsLine();

        $axes = [];

        foreach (self::BANDS as $key => $band) {
            $axes[] = $this->bandAxis($key, $band, $rows);
        }

        $axes[] = $this->errorAxis(
            'speed',
            'Speed',
            'short and long misses',
            $split['speed'],
            $attempts,
            self::BASELINE_SPEED_ERROR,
        );

        return [...$axes, ...$this->lineAxes($stats, $split['line'], $attempts, $splitLine)];
    }

    /**
     * Whether this scope has enough classified misses to split the line axis.
     */
    public function canSplitLine(PuttStats $stats): bool
    {
        return $stats->lineMissCauses()['classified'] >= self::MIN_ATTEMPTS;
    }

    /**
     * One Line axis, or two once enough left and right misses carry a cause.
     *
     * The classified sample is extrapolated across every line miss rather than
     * counted raw: a history that is half unclassified would otherwise show both
     * halves as small, flattering errors that simply have not been labelled.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineAxes(PuttStats $stats, int $lineMisses, int $attempts, ?bool $splitLine): array
    {
        $causes = $stats->lineMissCauses();

        if (! ($splitLine ?? $this->canSplitLine($stats))) {
            return [$this->errorAxis(
                'line',
                'Line',
                'left and right misses',
                $lineMisses,
                $attempts,
                self::BASELINE_LINE_ERROR,
            )];
        }

        return [
            $this->errorAxis(
                'stroke',
                'Stroke',
                'pushes and pulls',
                (int) round($lineMisses * $causes['stroke_percent'] / 100),
                $attempts,
                self::BASELINE_STROKE_ERROR,
            ),
            $this->errorAxis(
                'read',
                'Read',
                'misread breaks',
                (int) round($lineMisses * $causes['read_percent'] / 100),
                $attempts,
                self::BASELINE_READ_ERROR,
            ),
        ];
    }

    /**
     * Whether there is enough spread for the chart to say anything. Below this it
     * would be mostly empty spokes pretending to be a diagnosis.
     *
     * @param  array<int, array<string, mixed>>  $axes
     */
    public function isReadable(array $axes): bool
    {
        return collect($axes)->whereNotNull('score')->count() >= 3;
    }

    /**
     * The strongest and weakest scored axes, for the plain-language read under the
     * chart. Null when fewer than two axes have scores to compare.
     *
     * @param  array<int, array<string, mixed>>  $axes
     * @return array{best: array<string, mixed>, worst: array<string, mixed>}|null
     */
    public function extremes(array $axes): ?array
    {
        $scored = collect($axes)->whereNotNull('score')->sortBy('score')->values();

        if ($scored->count() < 2) {
            return null;
        }

        return ['best' => $scored->last(), 'worst' => $scored->first()];
    }

    /**
     * The make rate the reference putter manages from a given distance.
     */
    public function referenceMakeRate(float $feet): float
    {
        return 100 / (1 + ($feet / self::REFERENCE_HALF_DISTANCE) ** self::REFERENCE_STEEPNESS);
    }

    /**
     * Scored distance by distance and then averaged by attempts, rather than pooling
     * the band into one make rate. Pooling would let the distance mix drive the
     * score: "15 ft and out" means something very different when it is mostly 15 ft
     * than when it is mostly 30 ft.
     *
     * @param  array{label: string, note: string, min: int, max: int}  $band
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function bandAxis(string $key, array $band, Collection $rows): array
    {
        $slice = $rows->filter(
            fn (array $row): bool => $row['distance_ft'] >= $band['min'] && $row['distance_ft'] <= $band['max'],
        );

        $attempts = (int) $slice->sum('attempts');
        $sunk = (int) $slice->sum('sunk');
        $rate = $attempts > 0 ? round($sunk / $attempts * 100, 1) : 0.0;

        $weighted = $slice->sum(fn (array $row): float => $row['attempts']
            * ($row['make_percent'] / $this->referenceMakeRate((float) $row['distance_ft'])));

        return [
            'key' => $key,
            'label' => $band['label'],
            'note' => $band['note'],
            'attempts' => $attempts,
            'value' => $rate,
            'summary' => sprintf('%s%% made %s', $rate, $band['note']),
            'score' => $attempts >= self::MIN_ATTEMPTS
                ? $this->score($weighted / $attempts, 1.0)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorAxis(
        string $key,
        string $label,
        string $note,
        int $misses,
        int $attempts,
        float $baseline,
    ): array {
        $rate = $attempts > 0 ? $misses / $attempts : 0.0;
        $percent = round($rate * 100, 1);

        return [
            'key' => $key,
            'label' => $label,
            'note' => $note,
            'attempts' => $attempts,
            'value' => $percent,
            'summary' => sprintf('%s%% of putts lost to %s', $percent, $note),
            // Inverted: a lower error rate is a higher score.
            'score' => $attempts >= self::MIN_ATTEMPTS
                ? $this->score($baseline, max($rate, self::ERROR_FLOOR))
                : null,
        ];
    }

    /**
     * Matching the reference scores BASELINE_SCORE, doubling it scores full marks.
     */
    private function score(float $actual, float $reference): int
    {
        if ($reference <= 0.0) {
            return 0;
        }

        return (int) max(0, min(100, round($actual / $reference * self::BASELINE_SCORE)));
    }
}
