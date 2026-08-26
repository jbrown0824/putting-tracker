@php
    /**
     * @var array<int, array{label: string, colour: string, axes: array<int, array<string, mixed>>}> $shapes
     *      One shape draws a single profile; two overlay for a putter comparison.
     * @var string|null $profileNote Optional line rendered under the chart.
     */
    $shapes = collect($shapes)->values();
    $axes = $shapes->first()['axes'];
    $count = count($axes);

    $cx = 150;
    $cy = 142;
    $radius = 92;

    /** Start at twelve o'clock and step clockwise, so the first axis reads as the headline. */
    $angle = fn (int $index): float => -M_PI / 2 + ($index * 2 * M_PI / $count);

    $point = function (int $index, float $fraction) use ($cx, $cy, $radius, $angle): array {
        $a = $angle($index);

        return [
            round($cx + $radius * $fraction * cos($a), 2),
            round($cy + $radius * $fraction * sin($a), 2),
        ];
    };

    $polygon = fn (array $scores) => collect($scores)
        ->map(fn (?int $score, int $i) => implode(',', $point($i, ($score ?? 0) / 100)))
        ->implode(' ');

    $ring = fn (float $fraction) => collect(range(0, $count - 1))
        ->map(fn (int $i) => implode(',', $point($i, $fraction)))
        ->implode(' ');
@endphp

<div class="mt-2 rounded-lg bg-slate-900 px-2 py-3">
    <svg viewBox="0 0 300 290" class="w-full" role="img"
         aria-label="Putting profile across short, mid, lag, speed and line">
        {{-- Grid rings, faintest first. --}}
        @foreach ([0.25, 0.5, 0.75, 1.0] as $fraction)
            <polygon points="{{ $ring($fraction) }}" fill="none" stroke="rgb(51 65 85)"
                     stroke-width="{{ $fraction === 1.0 ? 1.2 : 0.7 }}"/>
        @endforeach

        @for ($i = 0; $i < $count; $i++)
            @php [$x, $y] = $point($i, 1.0); @endphp
            <line x1="{{ $cx }}" y1="{{ $cy }}" x2="{{ $x }}" y2="{{ $y }}" stroke="rgb(51 65 85)" stroke-width="0.7"/>
        @endfor

        @foreach ($shapes as $shape)
            @php $scores = array_column($shape['axes'], 'score'); @endphp
            <polygon points="{{ $polygon($scores) }}"
                     fill="{{ $shape['colour'] }}" fill-opacity="{{ $shapes->count() > 1 ? 0.18 : 0.28 }}"
                     stroke="{{ $shape['colour'] }}" stroke-width="2" stroke-linejoin="round"/>

            @foreach ($scores as $i => $score)
                @php [$x, $y] = $point($i, ($score ?? 0) / 100); @endphp
                <circle cx="{{ $x }}" cy="{{ $y }}" r="2.6" fill="{{ $shape['colour'] }}"/>
            @endforeach
        @endforeach

        {{-- Labels sit just outside the outer ring, anchored so they never overlap it. --}}
        @foreach ($axes as $i => $axis)
            @php
                [$x, $y] = $point($i, 1.19);
                $anchor = match (true) {
                    abs($x - $cx) < 6 => 'middle',
                    $x > $cx => 'start',
                    default => 'end',
                };
                $scored = $axis['score'] !== null;
            @endphp
            <text x="{{ $x }}" y="{{ $y }}" text-anchor="{{ $anchor }}"
                  class="{{ $scored ? 'fill-slate-300' : 'fill-slate-600' }} text-[11px] font-medium">
                {{ strtoupper($axis['label']) }}
            </text>
            {{-- Sample size only when one shape is drawn: on an overlay it would be
                 ambiguous which shape it counted. --}}
            @if ($shapes->count() === 1 || ! $scored)
                <text x="{{ $x }}" y="{{ $y + 11 }}" text-anchor="{{ $anchor }}" class="fill-slate-600 text-[9px]">
                    {{ $scored ? 'n='.$axis['attempts'] : 'need '.(\App\Services\PuttingProfile::MIN_ATTEMPTS - $axis['attempts']).' more' }}
                </text>
            @endif
        @endforeach
    </svg>

    @if ($shapes->count() > 1)
        @php
            /** An unscored axis is drawn at the centre, which would otherwise read as
                a catastrophic weakness rather than as missing data. */
            $thin = $shapes
                ->map(fn (array $shape): array => [
                    'label' => $shape['label'],
                    'unscored' => collect($shape['axes'])->whereNull('score')->count(),
                ])
                ->filter(fn (array $row): bool => $row['unscored'] > 0);
        @endphp

        <div class="mt-1 flex justify-center gap-4">
            @foreach ($shapes as $shape)
                <span class="flex items-center gap-1.5 text-[11px] text-slate-400">
                    <span class="h-2 w-2 rounded-full" style="background: {{ $shape['colour'] }}"></span>
                    {{ $shape['label'] }}
                </span>
            @endforeach
        </div>

        @foreach ($thin as $row)
            <p class="mt-1 text-center text-[10px] text-slate-600">
                {{ $row['label'] }} sits at zero on {{ $row['unscored'] }}
                {{ Str::plural('axis', $row['unscored']) }} for want of putts, not for want of skill.
            </p>
        @endforeach
    @endif
</div>

{{-- Column count follows the axis count, which changes when the line axis splits.
     Inline so it cannot depend on a Tailwind class being in the built bundle. --}}
<div class="mt-2 grid gap-1" style="grid-template-columns: repeat({{ $count }}, minmax(0, 1fr))">
    @foreach ($axes as $i => $axis)
        <div class="rounded bg-slate-900 px-1 py-1.5 text-center">
            <div class="text-[9px] uppercase tracking-wide text-slate-500">{{ $axis['label'] }}</div>
            @foreach ($shapes as $shape)
                @php $score = $shape['axes'][$i]['score']; @endphp
                <div class="text-xs font-medium" style="color: {{ $score === null ? 'rgb(71 85 105)' : $shape['colour'] }}">
                    {{ $score ?? '—' }}
                </div>
            @endforeach
        </div>
    @endforeach
</div>

@isset($profileNote)
    <p class="mt-1.5 text-[11px] leading-relaxed text-slate-500">{{ $profileNote }}</p>
@endisset
