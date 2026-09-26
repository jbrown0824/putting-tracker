@extends('layouts.app')

@section('title', 'Compare putters')

@section('content')
    <h1 class="pt-2 text-lg font-medium">
        Compare putters
        @if ($context !== null)
            <span class="text-slate-500">· {{ $context->label() }}</span>
        @endif
    </h1>

    @include('partials.putter-switch', ['putter' => null])
    @include('partials.context-switch')

    @if ($first === null || $second === null)
        <p class="mt-6 rounded-lg bg-slate-900 p-4 text-sm text-slate-400">
            Log putts with at least two putters to compare them.
            <a href="{{ route('settings') }}" class="text-emerald-400">Add putters</a> in Settings.
        </p>
    @else
    @php
        $firstRow = $headline[$first->id];
        $secondRow = $headline[$second->id];
        $carry = array_filter(['context' => $context?->value]);
    @endphp

    {{-- The pair under comparison. Each picker excludes whatever the other holds. --}}
    <div class="mt-3 grid grid-cols-[1fr_auto_1fr] items-center gap-2" x-data>
        @foreach ([['first', $first, $second], ['second', $second, $first]] as [$slot, $selected, $opposite])
            @if ($slot === 'second')
                <span class="text-xs text-slate-500">vs</span>
            @endif
            <select aria-label="{{ $slot === 'first' ? 'First putter' : 'Second putter' }}"
                    @change="window.location = $event.target.value"
                    class="w-full appearance-none truncate rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm {{ $slot === 'first' ? 'text-slate-300' : 'text-emerald-300' }} focus:outline-none">
                @foreach ($candidates->reject(fn ($option) => $option->is($opposite)) as $option)
                    <option @selected($option->is($selected))
                            value="{{ route('stats.compare', $carry + ($slot === 'first'
                                ? ['first' => $option->id, 'second' => $second->id]
                                : ['first' => $first->id, 'second' => $option->id])) }}">
                        {{ $option->name }}
                    </option>
                @endforeach
            </select>
        @endforeach
    </div>

    <section class="mt-4">
        @if ($verdict['state'] === 'recommended')
            <div class="rounded-lg border-l-2 border-emerald-500 bg-slate-900 p-4">
                <div class="text-[11px] uppercase tracking-wide text-emerald-500">Recommended</div>
                <div class="mt-1 text-xl font-medium text-slate-50">{{ $verdict['putter']->name }}</div>
                <p class="mt-2 text-xs leading-relaxed text-slate-400">{{ $verdict['message'] }}</p>
            </div>
        @elseif ($verdict['state'] === 'too_close')
            <div class="rounded-lg border-l-2 border-amber-500 bg-slate-900 p-4">
                <div class="text-[11px] uppercase tracking-wide text-amber-500">Too close to call</div>
                <div class="mt-1 text-xl font-medium text-slate-50">{{ $verdict['first_percent'] }}% vs {{ $verdict['second_percent'] }}%</div>
                <p class="mt-2 text-xs leading-relaxed text-slate-400">{{ $verdict['message'] }}</p>
            </div>
        @else
            <div class="rounded-lg border-l-2 border-slate-600 bg-slate-900 p-4">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Keep logging</div>
                <p class="mt-2 text-xs leading-relaxed text-slate-400">{{ $verdict['message'] }}</p>
            </div>
        @endif
    </section>

    @php
        $profile = app(\App\Services\PuttingProfile::class);
        $firstAxes = $profiles[$first->id];
        $secondAxes = $profiles[$second->id];
    @endphp

    @if ($profile->isReadable($firstAxes) || $profile->isReadable($secondAxes))
        <section class="mt-6">
            <h2 class="text-sm font-medium text-slate-300">Play style</h2>
            <p class="mt-1 text-[11px] text-slate-500">
                Where the two strokes differ in shape. Each axis is scored against a solid amateur, who sits at {{ \App\Services\PuttingProfile::BASELINE_SCORE }}.
            </p>

            @include('partials.putting-profile', [
                'shapes' => [
                    ['label' => $first->name, 'colour' => 'rgb(148 163 184)', 'axes' => $firstAxes],
                    ['label' => $second->name, 'colour' => 'rgb(16 185 129)', 'axes' => $secondAxes],
                ],
            ])
        </section>
    @endif

    <section class="mt-6">
        <h2 class="text-sm font-medium text-slate-300">Head to head</h2>
        <div class="mt-2 overflow-hidden rounded-lg bg-slate-900">
            <div class="grid grid-cols-3 border-b border-slate-800 px-3 py-2 text-[11px] text-slate-500">
                <span></span>
                <span class="truncate text-right">{{ $first->name }}</span>
                <span class="truncate text-right">{{ $second->name }}</span>
            </div>

            @php
                $firstAdjusted = $matchedRates['scopes']['first'];
                $secondAdjusted = $matchedRates['scopes']['second'];

                $rows = [
                    ['Putts', $firstRow['attempts'], $secondRow['attempts'], false],
                    ['Make rate', $firstRow['make_percent'].'%', $secondRow['make_percent'].'%', true],
                    // The row that actually settles it: the same two putters over the
                    // same mix of putts, rather than over whatever each happened to face.
                    ...($firstAdjusted['reliable'] ? [[
                        'Levelled',
                        $firstAdjusted['adjusted_percent'].'%',
                        $secondAdjusted['adjusted_percent'].'%',
                        true,
                    ]] : []),
                    // Splitting by context is meaningless once the page is filtered to one.
                    ...($context !== null ? [] : [
                        ['Inside', $firstRow['inside']['make_percent'].'%', $secondRow['inside']['make_percent'].'%', true],
                        ['Outside', $firstRow['outside']['make_percent'].'%', $secondRow['outside']['make_percent'].'%', true],
                    ]),
                    ['50% distance', $firstRow['fifty_percent_distance'] ? $firstRow['fifty_percent_distance'].'ft' : '—', $secondRow['fifty_percent_distance'] ? $secondRow['fifty_percent_distance'].'ft' : '—', false],
                    ['Speed misses', $firstRow['speed_percent'].'%', $secondRow['speed_percent'].'%', false],
                    ['Line misses', $firstRow['line_percent'].'%', $secondRow['line_percent'].'%', false],
                    ['Lip outs', $firstRow['lip_out_percent'].'%', $secondRow['lip_out_percent'].'%', false],
                ];
            @endphp

            @foreach ($rows as [$label, $firstValue, $secondValue, $highlight])
                @php
                    $firstWins = $highlight && (float) $firstValue > (float) $secondValue;
                    $secondWins = $highlight && (float) $secondValue > (float) $firstValue;
                @endphp
                <div class="grid grid-cols-3 px-3 py-2 text-sm {{ ! $loop->last ? 'border-b border-slate-800/60' : '' }}">
                    <span class="text-[11px] text-slate-500">{{ $label }}</span>
                    <span class="text-right {{ $firstWins ? 'font-medium text-emerald-300' : 'text-slate-300' }}">{{ $firstValue }}</span>
                    <span class="text-right {{ $secondWins ? 'font-medium text-emerald-300' : 'text-slate-300' }}">{{ $secondValue }}</span>
                </div>
            @endforeach
        </div>
        <p class="mt-1 text-[11px] leading-relaxed text-slate-600">
            Raw totals{{ $context !== null ? ' for '.strtolower($context->label()).' putts' : '' }} include putts only one putter has played.
            @if ($firstAdjusted['reliable'])
                The levelled row puts both over the same mix, matched on
                {{ \App\Services\AdjustedRate::describeDimensions($matchedRates['dimensions']) }} across {{ $matchedRates['sample'] }} putts in
                {{ $matchedRates['cells'] }} groups — that is the row the verdict is based on.
            @else
                There is not yet enough overlap between the two to level them against a shared mix.
            @endif
        </p>
    </section>

    @include('partials.context-breakdown')

    @if ($byDistance->isNotEmpty())
        <section class="mt-6">
            <h2 class="text-sm font-medium text-slate-300">Matched distances</h2>
            <p class="mt-1 text-[11px] text-slate-500">Only distances you have hit at least {{ \App\Services\PutterComparison::MIN_ATTEMPTS_PER_DISTANCE }} times with each putter.</p>
            <div class="mt-2 space-y-2">
                @foreach ($byDistance as $row)
                    <div>
                        <div class="flex justify-between text-[11px] text-slate-500">
                            <span>{{ $row['distance_ft'] }}ft</span>
                            <span class="{{ abs($row['gap']) >= 10 ? 'text-amber-400' : '' }}">
                                {{ $row['gap'] > 0 ? $second->name : $first->name }} +{{ abs($row['gap']) }}pts
                            </span>
                        </div>
                        <div class="mt-1 space-y-1">
                            @foreach ([[$first->name, $row['first_percent'], $row['first_attempts'], 'bg-slate-500/50'], [$second->name, $row['second_percent'], $row['second_attempts'], 'bg-emerald-500/40']] as [$label, $percent, $attempts, $colour])
                                <div class="flex items-center gap-2">
                                    <span class="w-16 shrink-0 truncate text-[10px] text-slate-600">{{ $label }}</span>
                                    <div class="h-3.5 flex-1 overflow-hidden rounded bg-slate-900">
                                        <div class="h-full {{ $colour }}" style="width: {{ max(2, $percent) }}%"></div>
                                    </div>
                                    <span class="w-16 shrink-0 text-right text-[10px] text-slate-500">{{ $percent }}% <span class="text-slate-700">n={{ $attempts }}</span></span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if (array_filter($strengths))
        <section class="mt-6 mb-4">
            <h2 class="text-sm font-medium text-slate-300">Strengths</h2>
            <p class="mt-1 text-[11px] text-slate-500">Each putter's edge is the other's weakness. Claims only appear once there is enough data behind them.</p>

            <div class="mt-2 space-y-3">
                @foreach ([$first, $second] as $option)
                    <div class="rounded-lg bg-slate-900 p-3">
                        <div class="text-xs font-medium text-slate-200">{{ $option->name }}</div>

                        @forelse ($strengths[$option->id] as $strength)
                            <div class="mt-2 border-l-2 border-emerald-500/60 pl-2.5">
                                <div class="text-[11px] font-medium text-emerald-300">{{ $strength['headline'] }}</div>
                                <p class="mt-0.5 text-[11px] leading-relaxed text-slate-400">{{ $strength['detail'] }}</p>
                            </div>
                        @empty
                            <p class="mt-1.5 text-[11px] text-slate-600">Nothing it does clearly better yet.</p>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </section>
    @endif
    @endif
@endsection
