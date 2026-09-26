@php
    /**
     * A running total against the straight-line pace a total goal requires.
     *
     * @var array<int, array<string, mixed>> $series one point per day
     */
    $days = collect($series)->values();
    $happened = $days->filter(fn (array $day): bool => $day['cumulative'] !== null)->values();
    $maxValue = max(1, (int) $days->max('target_cumulative'), (int) $happened->max('cumulative'));
    $step = $days->count() > 1 ? 300 / ($days->count() - 1) : 0;
    $point = fn (int|float $value, int $index): string => round($index * $step, 2).','.round(110 - ($value / $maxValue) * 100, 2);
    $targetLine = $days->filter(fn (array $day): bool => $day['target_cumulative'] !== null)
        ->map(fn (array $day, int $index): string => $point($day['target_cumulative'], $index))->implode(' ');
    $actualLine = $happened->map(fn (array $day, int $index): string => $point($day['cumulative'], $index))->implode(' ');
@endphp

@if ($days->isNotEmpty())
    <div class="mt-2 rounded-lg bg-slate-950/60 p-2">
        <svg viewBox="0 0 300 120" class="w-full" role="img" aria-label="Running total against the pace needed">
            @if ($targetLine !== '')
                <polyline points="{{ $targetLine }}" fill="none" stroke="rgb(71 85 105)" stroke-width="1.5" stroke-dasharray="4 3"/>
            @endif
            @if ($happened->count() > 1)
                <polyline points="{{ $actualLine }}" fill="none" stroke="rgb(16 185 129)" stroke-width="2.5"/>
            @elseif ($happened->count() === 1)
                <circle cx="0" cy="{{ round(110 - ($happened[0]['cumulative'] / $maxValue) * 100, 2) }}" r="3" fill="rgb(16 185 129)"/>
            @endif
        </svg>
        <div class="mt-1 flex justify-between text-[10px] text-slate-600">
            <span>{{ $days->first()['label'] }}</span>
            <span class="text-emerald-500">— you</span>
            @if ($targetLine !== '')
                <span class="text-slate-500">--- pace</span>
            @endif
            <span>{{ $days->last()['label'] }}</span>
        </div>
    </div>
@endif
