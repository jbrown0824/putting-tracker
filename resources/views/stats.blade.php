@extends('layouts.app')

@section('title', 'Stats')

@section('content')
    <h1 class="pt-2 text-lg font-medium">Stats</h1>

    @include('partials.stats-scope')

    {{-- Scoped to whatever the picker selected, so an empty view says so instead of drawing zeroes. --}}
    @php $totalPutts = $byDistance->sum('attempts'); @endphp

    @if ($session !== null)
        <div class="mt-3 rounded-lg bg-slate-900 p-3">
            <div class="flex items-baseline justify-between">
                <span class="text-sm font-medium text-slate-100">{{ $session->started_at->format('M j, g:ia') }}</span>
                <a href="{{ route('sessions.show', $session) }}" class="text-[11px] text-emerald-400">Every putt ›</a>
            </div>
            <div class="mt-1 text-[11px] text-slate-500">
                {{ $session->context->label() }} · {{ $session->putter->label() }}
                @if ($session->location) · {{ $session->location }} @endif
                @if ($session->surface) · {{ $session->surface }} @endif
            </div>
            <div class="mt-2 text-sm text-slate-300">
                {{ $totalPutts }} putts · {{ $dial['sunk']['count'] }} sunk
                <span class="text-slate-500">({{ $dial['sunk']['percent'] }}%)</span>
            </div>
        </div>
    @else
        @include('partials.putter-switch')
        @include('partials.context-switch')

        @if ($progress === null)
            <p class="mt-4 text-sm text-slate-400">No challenge configured yet.</p>
        @else
            <div class="mt-3 grid grid-cols-3 gap-2">
                @foreach ([
                    ['Putts', $progress['total'], '/ '.$progress['target_total']],
                    ['Outside', $progress['outside'], '/ '.$progress['target_outside_min']],
                    [$putter->label().' make', $dial['sunk']['percent'].'%', null],
                ] as [$label, $value, $suffix])
                    <div class="rounded-lg bg-slate-900 p-3">
                        <div class="text-[11px] text-slate-500">{{ $label }}</div>
                        <div class="mt-0.5 whitespace-nowrap text-base font-medium">
                            {{ $value }}@if ($suffix)<span class="text-[11px] font-normal text-slate-500"> {{ $suffix }}</span>@endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-2 rounded-lg bg-slate-900 p-3 text-xs text-slate-400">
                {{ $progress['days_remaining'] }} days left · {{ $progress['per_day_needed'] }} putts/day
                ({{ $progress['outside_per_day_needed'] }}/day outside) to finish
                <span class="mt-1 block text-[11px] text-slate-600">
                    Challenge totals count every putt. Everything below is {{ $putter->label() }}{{ $context !== null ? ', '.strtolower($context->label()).' only' : ' only' }}.
                </span>
            </div>
        @endif
    @endif

        @if ($totalPutts > 0 && $adjusted['reliable'])
            <section class="mt-5">
                <h2 class="text-sm font-medium text-slate-300">Make rate, levelled</h2>
                <p class="mt-1 text-[11px] text-slate-500">
                    What this make rate would be over your usual mix of putts, so it can be
                    compared with any other scope instead of only with itself.
                </p>

                <div class="mt-2 rounded-lg bg-slate-900 p-3">
                    <div class="flex items-baseline justify-center gap-4">
                        <div class="text-center">
                            <div class="text-[10px] uppercase tracking-wide text-slate-500">Raw</div>
                            <div class="text-xl font-medium text-slate-400">{{ $adjusted['raw_percent'] }}%</div>
                        </div>
                        <div class="text-slate-700">→</div>
                        <div class="text-center">
                            <div class="text-[10px] uppercase tracking-wide text-emerald-500">Levelled</div>
                            <div class="text-xl font-medium text-emerald-300">{{ $adjusted['adjusted_percent'] }}%</div>
                        </div>
                    </div>

                    <p class="mt-2 text-center text-[11px] leading-relaxed text-slate-400">{{ $adjusted['note'] }}</p>
                    <p class="mt-1 text-center text-[10px] text-slate-600">
                        Matched on {{ \App\Services\AdjustedRate::describeDimensions($adjusted['dimensions']) }} across
                        {{ $adjusted['matched_attempts'] }} putts ({{ $adjusted['coverage_percent'] }}% of your mix).
                    </p>
                </div>
            </section>
        @endif

        @if ($insights !== [])
            <section class="mt-5">
                <h2 class="text-sm font-medium text-slate-300">What the data says</h2>
                <ul class="mt-2 space-y-2">
                    @foreach ($insights as $insight)
                        <li class="rounded-lg border-l-2 border-emerald-500 bg-slate-900 px-3 py-2 text-xs leading-relaxed text-slate-300">
                            {{ $insight }}
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @php
            $profile = app(\App\Services\PuttingProfile::class);
            $extremes = $profile->extremes($profileAxes);
        @endphp

        @if ($profile->isReadable($profileAxes))
            <section class="mt-6">
                <h2 class="text-sm font-medium text-slate-300">Play style</h2>
                <p class="mt-1 text-[11px] text-slate-500">
                    Each axis is scored against a solid amateur, who sits at {{ \App\Services\PuttingProfile::BASELINE_SCORE }}.
                    The shape matters more than the size.
                </p>

                @include('partials.putting-profile', [
                    'shapes' => [[
                        'label' => $session !== null ? 'This session' : $putter->label(),
                        'colour' => 'rgb(16 185 129)',
                        'axes' => $profileAxes,
                    ]],
                    'profileNote' => $extremes === null ? null : sprintf(
                        'Strongest: %s — %s. Weakest: %s — %s.',
                        strtolower($extremes['best']['label']),
                        $extremes['best']['summary'],
                        strtolower($extremes['worst']['label']),
                        $extremes['worst']['summary'],
                    ),
                ])
            </section>
        @endif

        @if ($clockPositions['classified'] > 0)
            <section class="mt-6">
                <h2 class="text-sm font-medium text-slate-300">Around the hole</h2>
                <p class="mt-1 text-[11px] text-slate-500">
                    Make rate by where the ball sat, shaded against your own best position.
                </p>

                @include('partials.clock-heatmap', ['positions' => $clockPositions['positions']])

                @php
                    $slopeRows = collect($clockPositions['slopes'])->filter(fn (array $row): bool => $row['attempts'] >= 8);
                    $breakRows = collect($clockPositions['breaks'])->filter(fn (array $row): bool => $row['attempts'] >= 8);
                @endphp

                @foreach ([['Uphill vs. downhill', $slopeRows], ['Which way it breaks', $breakRows]] as [$heading, $rows])
                    @if ($rows->count() > 1)
                        <div class="mt-3">
                            <div class="text-[11px] text-slate-500">{{ $heading }}</div>
                            <div class="mt-1 space-y-1">
                                @foreach ($rows->sortByDesc('make_percent') as $row)
                                    <div class="flex items-center gap-2">
                                        <span class="w-20 shrink-0 text-[10px] text-slate-500">{{ $row['label'] }}</span>
                                        <div class="h-3.5 flex-1 overflow-hidden rounded bg-slate-900">
                                            <div class="h-full bg-emerald-500/40" style="width: {{ max(2, $row['make_percent']) }}%"></div>
                                        </div>
                                        <span class="w-16 shrink-0 text-right text-[10px] text-slate-500">
                                            {{ $row['make_percent'] }}% <span class="text-slate-700">n={{ $row['attempts'] }}</span>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach

                @if ($clockPositions['unclassified'] > 0)
                    <p class="mt-2 text-[10px] text-slate-600">
                        {{ $clockPositions['unclassified'] }} putts were logged before positions were tracked
                        and sit outside this section — they are unclassified, not flat.
                    </p>
                @endif
            </section>
        @endif

        @if ($totalPutts > 0)
            <section class="mt-6">
                <h2 class="text-sm font-medium text-slate-300">Your miss pattern</h2>
                <div class="mt-2 flex justify-center rounded-lg bg-slate-900 py-4">
                    <svg viewBox="0 0 240 240" class="w-full max-w-[260px]" role="img"
                         aria-label="Distribution of putt results across the dial zones">
                        @foreach ([
                            ['miss_long', 'M120 120 L38.7 38.7 A115 115 0 0 1 201.3 38.7 Z', 120, 42],
                            ['miss_right', 'M120 120 L201.3 38.7 A115 115 0 0 1 201.3 201.3 Z', 198, 116],
                            ['miss_short', 'M120 120 L201.3 201.3 A115 115 0 0 1 38.7 201.3 Z', 120, 192],
                            ['miss_left', 'M120 120 L38.7 201.3 A115 115 0 0 1 38.7 38.7 Z', 42, 116],
                        ] as [$key, $path, $tx, $ty])
                            @php $zone = $dial[$key]; @endphp
                            <path d="{{ $path }}" fill="rgb(30 41 59)" fill-opacity="{{ min(1, 0.25 + ($zone['percent'] / 100) * 2.5) }}" stroke="rgb(2 6 23)" stroke-width="2"/>
                            <text x="{{ $tx }}" y="{{ $ty }}" text-anchor="middle" class="fill-slate-200 text-[13px] font-medium">{{ $zone['percent'] }}%</text>
                            <text x="{{ $tx }}" y="{{ $ty + 12 }}" text-anchor="middle" class="fill-slate-500 text-[9px]">{{ strtoupper($zone['label']) }}</text>
                        @endforeach

                        <circle cx="120" cy="120" r="54" fill="rgb(2 6 23)"/>
                        <circle cx="120" cy="120" r="50" fill="rgb(16 185 129)" fill-opacity="0.18" stroke="rgb(16 185 129)" stroke-width="3"/>
                        <text x="120" y="118" text-anchor="middle" class="fill-emerald-300 text-[19px] font-medium">{{ $dial['sunk']['percent'] }}%</text>
                        <text x="120" y="133" text-anchor="middle" class="fill-emerald-500/70 text-[10px]">SUNK</text>
                    </svg>
                </div>
                <p class="mt-1 text-center text-[11px] text-slate-500">
                    Lip outs: {{ $dial['lip_out']['count'] }} ({{ $dial['lip_out']['percent'] }}% of all putts)
                </p>
            </section>

            @php $classified = $speedVsLine['speed'] + $speedVsLine['line']; @endphp
            @if ($classified > 0)
                <section class="mt-6">
                    <h2 class="text-sm font-medium text-slate-300">Speed vs. line</h2>
                    <p class="mt-1 text-[11px] text-slate-500">Where your misses come from, excluding lip outs.</p>
                    <div class="mt-2 flex h-9 overflow-hidden rounded-lg">
                        <div class="flex items-center justify-center bg-amber-500/25 text-[11px] font-medium text-amber-200"
                             style="width: {{ max(12, $speedVsLine['speed_percent']) }}%">
                            {{ $speedVsLine['speed_percent'] }}%
                        </div>
                        <div class="flex items-center justify-center bg-sky-500/25 text-[11px] font-medium text-sky-200"
                             style="width: {{ max(12, $speedVsLine['line_percent']) }}%">
                            {{ $speedVsLine['line_percent'] }}%
                        </div>
                    </div>
                    <div class="mt-1 flex justify-between text-[11px] text-slate-500">
                        <span>Speed ({{ $speedVsLine['speed'] }} short/long)</span>
                        <span>Line ({{ $speedVsLine['line'] }} left/right)</span>
                    </div>

                    @if ($lineMissCauses['classified'] >= 5)
                        <div class="mt-3 rounded-lg bg-slate-900 p-3">
                            <div class="text-[11px] text-slate-400">Of those line misses</div>

                            <div class="mt-1.5 flex h-7 overflow-hidden rounded">
                                <div class="flex items-center justify-center bg-slate-500/40 text-[10px] font-medium text-slate-100"
                                     style="width: {{ max(12, $lineMissCauses['stroke_percent']) }}%">
                                    {{ $lineMissCauses['stroke_percent'] }}%
                                </div>
                                <div class="flex items-center justify-center bg-sky-500/30 text-[10px] font-medium text-sky-200"
                                     style="width: {{ max(12, $lineMissCauses['read_percent']) }}%">
                                    {{ $lineMissCauses['read_percent'] }}%
                                </div>
                            </div>

                            <div class="mt-1 flex justify-between text-[10px] text-slate-500">
                                <span>Push / pull ({{ $lineMissCauses['stroke'] }})</span>
                                <span>Misread ({{ $lineMissCauses['read'] }})</span>
                            </div>

                            @php
                                $inside = $lineMissCauses['by_context'][\App\Enums\PuttContext::Inside->value];
                                $outside = $lineMissCauses['by_context'][\App\Enums\PuttContext::Outside->value];
                            @endphp

                            @if ($inside['classified'] >= 5 && $outside['classified'] >= 5)
                                <div class="mt-2 border-t border-slate-800 pt-2 text-[10px] text-slate-500">
                                    Misreads: <span class="text-slate-300">{{ $inside['read_percent'] }}%</span> inside ·
                                    <span class="text-slate-300">{{ $outside['read_percent'] }}%</span> outside
                                </div>
                            @endif

                            @if ($lineMissCauses['unclassified'] > 0)
                                <div class="mt-1 text-[10px] text-slate-600">
                                    {{ $lineMissCauses['unclassified'] }} line {{ Str::plural('miss', $lineMissCauses['unclassified']) }} logged without a cause.
                                </div>
                            @endif
                        </div>
                    @endif
                </section>
            @endif

            @if ($byDistance->isNotEmpty())
                <section class="mt-6">
                    <h2 class="text-sm font-medium text-slate-300">Make rate by distance</h2>
                    @if ($fiftyPercentDistance !== null)
                        <p class="mt-1 text-[11px] text-slate-500">You cross 50% at about {{ $fiftyPercentDistance }} ft.</p>
                    @endif
                    <div class="mt-2 space-y-1.5">
                        @foreach ($byDistance as $row)
                            <div class="flex items-center gap-2">
                                <span class="w-10 shrink-0 text-right text-[11px] text-slate-500">{{ $row['distance_ft'] }}ft</span>
                                <div class="h-5 flex-1 overflow-hidden rounded bg-slate-900">
                                    <div class="flex h-full items-center justify-end bg-emerald-500/40 pr-1.5 text-[10px] text-emerald-100"
                                         style="width: {{ max(2, $row['make_percent']) }}%">
                                        {{ $row['make_percent'] > 12 ? $row['make_percent'].'%' : '' }}
                                    </div>
                                </div>
                                <span class="w-14 shrink-0 text-[10px] text-slate-600">n={{ $row['attempts'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="mt-6">
                    <h2 class="text-sm font-medium text-slate-300">Speed bias by distance</h2>
                    <p class="mt-1 text-[11px] text-slate-500">Left of center means you leave putts short; right means you run them past. Distances with fewer than 5 speed misses are hidden.</p>
                    <div class="mt-2 space-y-1.5">
                        @foreach ($byDistance->filter(fn ($row) => $row['short'] + $row['long'] >= 5) as $row)
                            @php $bias = $row['speed_bias']; @endphp
                            <div class="flex items-center gap-2">
                                <span class="w-10 shrink-0 text-right text-[11px] text-slate-500">{{ $row['distance_ft'] }}ft</span>
                                <div class="relative h-5 flex-1 rounded bg-slate-900">
                                    <div class="absolute inset-y-0 left-1/2 w-px bg-slate-700"></div>
                                    <div class="absolute inset-y-0 {{ $bias < 0 ? 'bg-rose-500/40' : 'bg-sky-500/40' }}"
                                         style="{{ $bias < 0
                                            ? 'right: 50%; width: '.(abs($bias) / 2).'%'
                                            : 'left: 50%; width: '.(abs($bias) / 2).'%' }}"></div>
                                </div>
                                <span class="w-14 shrink-0 text-[10px] text-slate-600">
                                    {{ $row['short'] }}s/{{ $row['long'] }}l
                                </span>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- Empty by construction once a context filter is on: there is only one side left. --}}
            @if ($insideVsOutside->isNotEmpty())
                <section class="mt-6">
                    <h2 class="text-sm font-medium text-slate-300">Inside vs. outside by distance</h2>
                    <p class="mt-1 text-[11px] text-slate-500">{{ $putter->label() }} only, at distances you have played in both.</p>
                    <div class="mt-2 space-y-2">
                        @foreach ($insideVsOutside as $row)
                            <div>
                                <div class="flex justify-between text-[11px] text-slate-500">
                                    <span>{{ $row['distance_ft'] }}ft</span>
                                    <span class="{{ $row['gap'] >= 15 ? 'text-amber-400' : '' }}">
                                        gap {{ $row['gap'] > 0 ? '+' : '' }}{{ $row['gap'] }}pts
                                    </span>
                                </div>
                                <div class="mt-1 space-y-1">
                                    @foreach ([['Inside', $row['inside_percent'], 'bg-slate-500/50'], ['Outside', $row['outside_percent'], 'bg-emerald-500/40']] as [$label, $percent, $colour])
                                        <div class="flex items-center gap-2">
                                            <span class="w-12 shrink-0 text-[10px] text-slate-600">{{ $label }}</span>
                                            <div class="h-3.5 flex-1 overflow-hidden rounded bg-slate-900">
                                                <div class="h-full {{ $colour }}" style="width: {{ max(2, $percent) }}%"></div>
                                            </div>
                                            <span class="w-10 shrink-0 text-[10px] text-slate-500">{{ $percent }}%</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @else
            <p class="mt-6 rounded-lg bg-slate-900 p-4 text-sm text-slate-400">
                No {{ $context !== null ? strtolower($context->label()).' ' : '' }}putts logged with the {{ strtolower($putter->label()) }} yet.
                Head to the Log tab, switch to it, and start tapping.
            </p>
        @endif

        @if ($session === null)
            @include('partials.context-breakdown')
        @endif

        {{-- The pace line is a challenge-window chart, so it has nothing to say about one session. --}}
        @if ($session === null && $dailyVolume->isNotEmpty())
            @php
                $maxCumulative = max(
                    $dailyVolume->max('target_cumulative') ?: 1,
                    $dailyVolume->filter(fn ($d) => $d['cumulative'] !== null)->max('cumulative') ?: 1,
                );
                $days = $dailyVolume->values();
                $step = $days->count() > 1 ? 300 / ($days->count() - 1) : 0;
                $point = fn ($value, $index) => round($index * $step, 2).','.round(110 - ($value / $maxCumulative) * 100, 2);
                $targetLine = $days->map(fn ($d, $i) => $point($d['target_cumulative'], $i))->implode(' ');
                $actualDays = $days->filter(fn ($d) => $d['cumulative'] !== null)->values();
                $actualLine = $actualDays->map(fn ($d, $i) => $point($d['cumulative'], $i))->implode(' ');
            @endphp
            <section class="mt-6 mb-4">
                <h2 class="text-sm font-medium text-slate-300">Pace</h2>
                <p class="mt-1 text-[11px] text-slate-500">Your cumulative total against the pace needed to finish.</p>
                <div class="mt-2 rounded-lg bg-slate-900 p-3">
                    <svg viewBox="0 0 300 120" class="w-full" role="img" aria-label="Cumulative putts against required pace">
                        <polyline points="{{ $targetLine }}" fill="none" stroke="rgb(71 85 105)" stroke-width="1.5" stroke-dasharray="4 3"/>
                        @if ($actualDays->count() > 1)
                            <polyline points="{{ $actualLine }}" fill="none" stroke="rgb(16 185 129)" stroke-width="2.5"/>
                        @elseif ($actualDays->count() === 1)
                            <circle cx="0" cy="{{ round(110 - ($actualDays[0]['cumulative'] / $maxCumulative) * 100, 2) }}" r="3" fill="rgb(16 185 129)"/>
                        @endif
                    </svg>
                    <div class="mt-1 flex justify-between text-[10px] text-slate-600">
                        <span>{{ $days->first()['label'] }}</span>
                        <span class="text-emerald-500">— you</span>
                        <span class="text-slate-500">--- target</span>
                        <span>{{ $days->last()['label'] }}</span>
                    </div>
                </div>
            </section>
        @endif
@endsection
