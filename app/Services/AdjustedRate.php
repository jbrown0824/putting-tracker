<?php

namespace App\Services;

/**
 * Make rates with the shot mix taken out of them.
 *
 * A raw make rate answers "how many went in", which is not the question anyone is
 * actually asking when they compare two putters or two months. Hit two hundred
 * tap-ins with the mallet and its raw rate climbs without the stroke improving at
 * all. The comparison is only meaningful once both sides are measured over the same
 * mix of putts.
 *
 * This is direct standardisation: split the putts into strata, take the make rate
 * within each stratum, then pool those rates back up using one shared set of
 * weights. It generalises the distance matching PutterComparison already does for
 * its verdict, extending it to context and — once there is enough of it — slope.
 */
class AdjustedRate
{
    /**
     * A stratum needs this many attempts before it can carry any weight. Below it
     * the within-cell rate is noise, and noise weighted heavily is worse than a raw
     * number.
     */
    public const MIN_CELL_ATTEMPTS = 5;

    /**
     * The adjusted figure has to cover this share of the reference mix before it
     * means anything. Under it the scope simply has not played enough of the same
     * putts, and the adjustment would be extrapolating across the gap.
     */
    public const MIN_COVERAGE = 0.6;

    /**
     * Slope only joins the strata once every scope has this many putts carrying a
     * position. Adding a dimension splits every cell, so it has to pay for itself
     * in signal before it is allowed to thin the sample.
     */
    public const MIN_SLOPE_CLASSIFIED = 60;

    /**
     * What one scope's make rate would be over the reference's mix of putts.
     *
     * The reference is normally the unscoped instance, which makes every scope
     * standardised against the same yardstick and therefore comparable with every
     * other — blade against mallet, inside against outside, this month against last.
     *
     * @return array<string, mixed>
     */
    public function standardised(PuttStats $scope, PuttStats $reference): array
    {
        $dimensions = $this->dimensionsFor([$scope, $reference]);
        $scopeCells = $this->cells($scope, $dimensions);
        $referenceCells = $this->cells($reference, $dimensions);

        $raw = $this->rawRate($scopeCells);
        $referenceTotal = array_sum(array_column($referenceCells, 'attempts'));

        $usable = array_filter(
            $referenceCells,
            fn (array $cell, string $key): bool => isset($scopeCells[$key])
                && $scopeCells[$key]['attempts'] >= self::MIN_CELL_ATTEMPTS,
            ARRAY_FILTER_USE_BOTH,
        );

        $weight = array_sum(array_column($usable, 'attempts'));
        $coverage = $referenceTotal > 0 ? $weight / $referenceTotal : 0.0;

        if ($weight <= 0 || $coverage < self::MIN_COVERAGE) {
            return $this->unreliable($raw, $dimensions, $coverage, $scopeCells);
        }

        $adjusted = 0.0;

        foreach ($usable as $key => $cell) {
            $adjusted += $cell['attempts'] * $this->cellRate($scopeCells[$key]);
        }

        $adjusted = round($adjusted / $weight, 1);

        return [
            'reliable' => true,
            'raw_percent' => $raw,
            'adjusted_percent' => $adjusted,
            'delta' => round($adjusted - $raw, 1),
            'attempts' => (int) array_sum(array_column($scopeCells, 'attempts')),
            'matched_attempts' => (int) $weight,
            'coverage_percent' => round($coverage * 100, 1),
            'dimensions' => $dimensions,
            'note' => $this->describe(round($adjusted - $raw, 1), $dimensions),
        ];
    }

    /**
     * Two or more scopes measured against the mix they share.
     *
     * Weights come from the thinnest scope in each cell, exactly as
     * PutterComparison weights its matched distances. Using the minimum stops a
     * scope being rewarded for having simply hit more of the easy putts.
     *
     * @param  array<string, PuttStats>  $scopes
     * @return array<string, mixed>
     */
    public function matched(array $scopes): array
    {
        $dimensions = $this->dimensionsFor(array_values($scopes));
        $cells = array_map(fn (PuttStats $stats): array => $this->cells($stats, $dimensions), $scopes);

        $shared = null;

        foreach ($cells as $set) {
            $keys = array_keys(array_filter(
                $set,
                fn (array $cell): bool => $cell['attempts'] >= self::MIN_CELL_ATTEMPTS,
            ));

            $shared = $shared === null ? $keys : array_intersect($shared, $keys);
        }

        $shared ??= [];
        $weights = [];

        foreach ($shared as $key) {
            $weights[$key] = min(array_map(fn (array $set): int => $set[$key]['attempts'], $cells));
        }

        $sample = array_sum($weights);
        $results = [];

        foreach ($cells as $label => $set) {
            $raw = $this->rawRate($set);

            if ($sample <= 0) {
                $results[$label] = [
                    'reliable' => false,
                    'raw_percent' => $raw,
                    'adjusted_percent' => null,
                    'delta' => null,
                    'attempts' => (int) array_sum(array_column($set, 'attempts')),
                ];

                continue;
            }

            $adjusted = 0.0;

            foreach ($weights as $key => $weight) {
                $adjusted += $weight * $this->cellRate($set[$key]);
            }

            $adjusted = round($adjusted / $sample, 1);

            $results[$label] = [
                'reliable' => true,
                'raw_percent' => $raw,
                'adjusted_percent' => $adjusted,
                'delta' => round($adjusted - $raw, 1),
                'attempts' => (int) array_sum(array_column($set, 'attempts')),
            ];
        }

        return [
            'scopes' => $results,
            'sample' => (int) $sample,
            'cells' => count($weights),
            'dimensions' => $dimensions,
        ];
    }

    /**
     * The dimensions in play, as a readable list: "distance and context", or
     * "distance, context and slope" once the third one engages.
     *
     * @param  array<int, string>  $dimensions
     */
    public static function describeDimensions(array $dimensions): string
    {
        if (count($dimensions) < 2) {
            return implode('', $dimensions);
        }

        $last = array_pop($dimensions);

        return implode(', ', $dimensions).' and '.$last;
    }

    /**
     * Which dimensions are safe to stratify on.
     *
     * Distance and context are always in — they are recorded on every putt ever
     * logged, so they work from day one. Slope waits until every scope has enough
     * putts carrying a position, because a dimension that is mostly unknown splits
     * the cells without telling you anything.
     *
     * @param  array<int, PuttStats>  $scopes
     * @return array<int, string>
     */
    private function dimensionsFor(array $scopes): array
    {
        $dimensions = ['distance', 'context'];

        $classified = array_map(
            fn (PuttStats $stats): int => $stats->byClockPosition()['classified'],
            $scopes,
        );

        if ($classified !== [] && min($classified) >= self::MIN_SLOPE_CLASSIFIED) {
            $dimensions[] = 'slope';
        }

        return $dimensions;
    }

    /**
     * Attempt and make counts per stratum, keyed by the dimensions in play.
     *
     * @param  array<int, string>  $dimensions
     * @return array<string, array{attempts: int, sunk: int}>
     */
    private function cells(PuttStats $stats, array $dimensions): array
    {
        $cells = [];

        foreach ($stats->cellCounts() as $row) {
            $key = $this->keyFor($row, $dimensions);

            $cells[$key] ??= ['attempts' => 0, 'sunk' => 0];
            $cells[$key]['attempts'] += $row['attempts'];
            $cells[$key]['sunk'] += $row['sunk'];
        }

        return $cells;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $dimensions
     */
    private function keyFor(array $row, array $dimensions): string
    {
        $parts = [];

        foreach ($dimensions as $dimension) {
            $parts[] = match ($dimension) {
                'distance' => PuttingProfile::bandFor((int) $row['distance_ft']),
                'context' => (string) $row['context'],
                // A putt with no position is its own stratum rather than being
                // folded into flat. It is compared only against other untagged
                // putts, which is the honest thing to do with unknown ground.
                'slope' => $row['slope'] ?? 'unknown',
                default => '',
            };
        }

        return implode('|', $parts);
    }

    /**
     * @param  array<string, array{attempts: int, sunk: int}>  $cells
     */
    private function rawRate(array $cells): float
    {
        $attempts = array_sum(array_column($cells, 'attempts'));
        $sunk = array_sum(array_column($cells, 'sunk'));

        return $attempts > 0 ? round($sunk / $attempts * 100, 1) : 0.0;
    }

    /**
     * @param  array{attempts: int, sunk: int}  $cell
     */
    private function cellRate(array $cell): float
    {
        return $cell['attempts'] > 0 ? $cell['sunk'] / $cell['attempts'] * 100 : 0.0;
    }

    /**
     * @param  array<int, string>  $dimensions
     * @param  array<string, array{attempts: int, sunk: int}>  $scopeCells
     * @return array<string, mixed>
     */
    private function unreliable(float $raw, array $dimensions, float $coverage, array $scopeCells): array
    {
        return [
            'reliable' => false,
            'raw_percent' => $raw,
            'adjusted_percent' => null,
            'delta' => null,
            'attempts' => (int) array_sum(array_column($scopeCells, 'attempts')),
            'matched_attempts' => 0,
            'coverage_percent' => round($coverage * 100, 1),
            'dimensions' => $dimensions,
            'note' => 'Not enough overlap with your usual mix of putts to adjust yet.',
        ];
    }

    /**
     * The delta in plain words. It is the interesting number: it says how far the
     * raw figure was from the truth, and in which direction.
     *
     * @param  array<int, string>  $dimensions
     */
    private function describe(float $delta, array $dimensions): string
    {
        $over = self::describeDimensions($dimensions);

        return match (true) {
            $delta <= -2.0 => sprintf(
                'Your raw rate flatters you by %s points — you have been hitting an easier mix than usual. Levelled for %s, it is lower.',
                abs($delta),
                $over,
            ),
            $delta >= 2.0 => sprintf(
                'Your raw rate undersells you by %s points — you have been hitting a harder mix than usual. Levelled for %s, it is higher.',
                $delta,
                $over,
            ),
            default => sprintf('Your mix of putts has been close to typical, so levelling for %s barely moves the number.', $over),
        };
    }
}
