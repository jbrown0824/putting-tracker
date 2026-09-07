@php
    use App\Enums\ClockPosition;

    /**
     * @var array<string, mixed> $positions Keyed by ClockPosition value, from PuttStats::byClockPosition().
     * @var int $minAttempts Below this a segment is drawn as an outline rather than shaded.
     */
    $minAttempts ??= 8;

    $cx = 90;
    $cy = 90;
    $outer = 78;
    $inner = 34;

    $point = fn (float $degrees, float $radius): array => [
        round($cx + $radius * cos(deg2rad($degrees)), 2),
        round($cy + $radius * sin(deg2rad($degrees)), 2),
    ];

    $segment = function (float $angle) use ($point, $outer, $inner): string {
        [$ax, $ay] = $point($angle - 22.5, $outer);
        [$bx, $by] = $point($angle + 22.5, $outer);
        [$cx2, $cy2] = $point($angle + 22.5, $inner);
        [$dx, $dy] = $point($angle - 22.5, $inner);

        return "M{$ax} {$ay} A{$outer} {$outer} 0 0 1 {$bx} {$by} L{$cx2} {$cy2} A{$inner} {$inner} 0 0 0 {$dx} {$dy} Z";
    };

    /** Shaded against the best-performing segment, so the contrast is between your
        own positions rather than against an arbitrary absolute scale. */
    $shaded = collect($positions)
        ->filter(fn (array $row): bool => $row['attempts'] >= $minAttempts);
    $best = $shaded->max('make_percent') ?: 100;

    $fill = function (array $row) use ($minAttempts, $best): string {
        if ($row['attempts'] < $minAttempts) {
            return 'none';
        }

        $strength = $best > 0 ? min(1.0, $row['make_percent'] / $best) : 0.0;

        return sprintf('rgb(16 185 129 / %.2f)', 0.08 + $strength * 0.62);
    };
@endphp

<div class="mt-2 rounded-lg bg-slate-900 px-2 py-3">
    <div class="flex justify-center">
        <svg viewBox="0 0 180 180" class="w-full max-w-[260px]" role="img"
             aria-label="Make rate by position around the hole">
            @foreach (ClockPosition::ring() as $position)
                @php $row = $positions[$position->value]; @endphp

                {{-- An under-sampled segment is drawn hollow. Shading it would put a
                     pale wedge next to a dark one and read as a weakness, when all it
                     means is that nobody has putted from there yet. --}}
                <path d="{{ $segment($position->angle()) }}"
                      fill="{{ $fill($row) }}"
                      stroke="{{ $row['attempts'] >= $minAttempts ? 'rgb(15 23 42)' : 'rgb(51 65 85)' }}"
                      stroke-width="1.5"
                      stroke-dasharray="{{ $row['attempts'] >= $minAttempts ? '0' : '3 3' }}">
                    <title>{{ $position->clockLabel() }} — {{ $position->label() }}: {{ $row['attempts'] >= $minAttempts ? $row['make_percent'].'% of '.$row['attempts'] : $row['attempts'].' putts, too few to score' }}</title>
                </path>

                @php [$lx, $ly] = $point($position->angle(), ($outer + $inner) / 2); @endphp
                <text x="{{ $lx }}" y="{{ $ly + 1 }}" text-anchor="middle"
                      class="{{ $row['attempts'] >= $minAttempts ? 'fill-slate-100' : 'fill-slate-600' }} text-[10px] font-medium">
                    {{ $row['attempts'] >= $minAttempts ? round($row['make_percent']).'%' : '—' }}
                </text>
                {{-- Brighter on a shaded segment, which is too dark for slate-600 to
                     read against. --}}
                <text x="{{ $lx }}" y="{{ $ly + 10 }}" text-anchor="middle"
                      class="{{ $row['attempts'] >= $minAttempts ? 'fill-emerald-100/70' : 'fill-slate-600' }} text-[8px]">
                    {{ str_replace(' o\'clock', '', $position->clockLabel()) }}
                </text>
            @endforeach

            @php $flat = $positions[ClockPosition::Flat->value]; @endphp
            <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $inner - 4 }}"
                    fill="{{ $fill($flat) }}" stroke="rgb(51 65 85)" stroke-width="1"/>
            <text x="{{ $cx }}" y="{{ $cy - 1 }}" text-anchor="middle"
                  class="{{ $flat['attempts'] >= $minAttempts ? 'fill-slate-100' : 'fill-slate-600' }} text-[10px] font-medium">
                {{ $flat['attempts'] >= $minAttempts ? round($flat['make_percent']).'%' : '—' }}
            </text>
            <text x="{{ $cx }}" y="{{ $cy + 8 }}" text-anchor="middle" class="fill-slate-600 text-[7px]">FLAT</text>
        </svg>
    </div>

    <p class="mt-1 text-center text-[10px] text-slate-600">
        12 o'clock is the high side. Dashed segments have fewer than {{ $minAttempts }} putts.
    </p>
</div>
