@php
    use App\Enums\ClockPosition;

    /**
     * The eight-segment position picker. Sticky, not per-putt: you set where you are
     * standing once and hit a handful of putts from there, so this never costs a tap
     * per putt the way a second dial would.
     */
    $cx = 90;
    $cy = 90;
    $outer = 78;
    $inner = 34;

    $point = fn (float $degrees, float $radius): array => [
        round($cx + $radius * cos(deg2rad($degrees)), 2),
        round($cy + $radius * sin(deg2rad($degrees)), 2),
    ];

    /** One annulus segment, 45 degrees wide and centred on the position's angle. */
    $segment = function (float $angle) use ($point, $outer, $inner): string {
        [$ax, $ay] = $point($angle - 22.5, $outer);
        [$bx, $by] = $point($angle + 22.5, $outer);
        [$cx2, $cy2] = $point($angle + 22.5, $inner);
        [$dx, $dy] = $point($angle - 22.5, $inner);

        return "M{$ax} {$ay} A{$outer} {$outer} 0 0 1 {$bx} {$by} L{$cx2} {$cy2} A{$inner} {$inner} 0 0 0 {$dx} {$dy} Z";
    };
@endphp

<div x-show="ringOpen" x-cloak class="mt-2 rounded-lg bg-slate-900 px-2 py-3">
    <p class="text-center text-[10px] leading-relaxed text-slate-500">
        Where your ball sits, with <span class="text-slate-300">12 o'clock on the high side</span>.
        Not a compass — 12 is always up the slope.
    </p>

    <div class="mt-2 flex justify-center">
        <svg viewBox="0 0 180 180" class="w-full max-w-[240px] touch-manipulation" role="group"
             aria-label="Ball position around the hole">
            @foreach (ClockPosition::ring() as $position)
                <path d="{{ $segment($position->angle()) }}"
                      class="cursor-pointer transition-colors"
                      :class="clockPosition === '{{ $position->value }}' ? 'fill-sky-500/60 stroke-sky-300' : 'fill-slate-800 stroke-slate-900 active:fill-slate-700'"
                      stroke-width="1.5"
                      @click="setClockPosition('{{ $position->value }}')"
                      role="button" aria-label="{{ $position->clockLabel() }} — {{ $position->label() }}"/>

                @php [$lx, $ly] = $point($position->angle(), ($outer + $inner) / 2); @endphp
                <text x="{{ $lx }}" y="{{ $ly + 3 }}" text-anchor="middle"
                      class="pointer-events-none text-[9px] font-medium"
                      :class="clockPosition === '{{ $position->value }}' ? 'fill-sky-50' : 'fill-slate-500'">
                    {{ $position === ClockPosition::Above ? '12' : ($position === ClockPosition::Below ? '6' : str_replace(' o\'clock', '', $position->clockLabel())) }}
                </text>
            @endforeach

            {{-- Flat sits in the middle because it is the absence of a position, not
                 one more of them. Indoor practice lives here. --}}
            <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $inner - 4 }}"
                    class="cursor-pointer transition-colors"
                    :class="clockPosition === 'flat' ? 'fill-slate-100' : 'fill-slate-800 active:fill-slate-700'"
                    @click="setClockPosition('flat')" role="button" aria-label="Flat, no slope"/>
            <text x="{{ $cx }}" y="{{ $cy + 3 }}" text-anchor="middle"
                  class="pointer-events-none text-[9px] font-medium"
                  :class="clockPosition === 'flat' ? 'fill-slate-900' : 'fill-slate-500'">FLAT</text>
        </svg>
    </div>
</div>
