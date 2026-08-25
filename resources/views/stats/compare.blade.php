@extends('layouts.app')

@section('title', 'Compare putters')

@php
    use App\Enums\Putter;

    $blade = $headline[Putter::Blade->value];
    $mallet = $headline[Putter::Mallet->value];
@endphp

@section('content')
    <h1 class="pt-2 text-lg font-medium">Blade vs. Mallet</h1>

    @include('partials.putter-switch', ['putter' => null])

    <section class="mt-4">
        @if ($verdict['state'] === 'recommended')
            <div class="rounded-lg border-l-2 border-emerald-500 bg-slate-900 p-4">
                <div class="text-[11px] uppercase tracking-wide text-emerald-500">Recommended</div>
                <div class="mt-1 text-xl font-medium text-slate-50">{{ $verdict['putter']->label() }}</div>
                <p class="mt-2 text-xs leading-relaxed text-slate-400">{{ $verdict['message'] }}</p>
            </div>
        @elseif ($verdict['state'] === 'too_close')
            <div class="rounded-lg border-l-2 border-amber-500 bg-slate-900 p-4">
                <div class="text-[11px] uppercase tracking-wide text-amber-500">Too close to call</div>
                <div class="mt-1 text-xl font-medium text-slate-50">{{ $verdict['blade_percent'] }}% vs {{ $verdict['mallet_percent'] }}%</div>
                <p class="mt-2 text-xs leading-relaxed text-slate-400">{{ $verdict['message'] }}</p>
            </div>
        @else
            <div class="rounded-lg border-l-2 border-slate-600 bg-slate-900 p-4">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">Keep logging</div>
                <p class="mt-2 text-xs leading-relaxed text-slate-400">{{ $verdict['message'] }}</p>
            </div>
        @endif
    </section>

    <section class="mt-6">
        <h2 class="text-sm font-medium text-slate-300">Head to head</h2>
        <div class="mt-2 overflow-hidden rounded-lg bg-slate-900">
            <div class="grid grid-cols-3 border-b border-slate-800 px-3 py-2 text-[11px] text-slate-500">
                <span></span>
                <span class="text-right">Blade</span>
                <span class="text-right">Mallet</span>
            </div>

            @php
                $rows = [
                    ['Putts', $blade['attempts'], $mallet['attempts'], false],
                    ['Make rate', $blade['make_percent'].'%', $mallet['make_percent'].'%', true],
                    ['Inside', $blade['inside']['make_percent'].'%', $mallet['inside']['make_percent'].'%', true],
                    ['Outside', $blade['outside']['make_percent'].'%', $mallet['outside']['make_percent'].'%', true],
                    ['50% distance', $blade['fifty_percent_distance'] ? $blade['fifty_percent_distance'].'ft' : '—', $mallet['fifty_percent_distance'] ? $mallet['fifty_percent_distance'].'ft' : '—', false],
                    ['Speed misses', $blade['speed_percent'].'%', $mallet['speed_percent'].'%', false],
                    ['Line misses', $blade['line_percent'].'%', $mallet['line_percent'].'%', false],
                    ['Lip outs', $blade['lip_out_percent'].'%', $mallet['lip_out_percent'].'%', false],
                ];
            @endphp

            @foreach ($rows as [$label, $bladeValue, $malletValue, $highlight])
                @php
                    $bladeWins = $highlight && (float) $bladeValue > (float) $malletValue;
                    $malletWins = $highlight && (float) $malletValue > (float) $bladeValue;
                @endphp
                <div class="grid grid-cols-3 px-3 py-2 text-sm {{ ! $loop->last ? 'border-b border-slate-800/60' : '' }}">
                    <span class="text-[11px] text-slate-500">{{ $label }}</span>
                    <span class="text-right {{ $bladeWins ? 'font-medium text-emerald-300' : 'text-slate-300' }}">{{ $bladeValue }}</span>
                    <span class="text-right {{ $malletWins ? 'font-medium text-emerald-300' : 'text-slate-300' }}">{{ $malletValue }}</span>
                </div>
            @endforeach
        </div>
        <p class="mt-1 text-[11px] text-slate-600">Raw totals — they include distances only one putter has played, which is why the verdict above uses matched distances instead.</p>
    </section>

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
                                {{ $row['gap'] > 0 ? 'mallet' : 'blade' }} +{{ abs($row['gap']) }}pts
                            </span>
                        </div>
                        <div class="mt-1 space-y-1">
                            @foreach ([['Blade', $row['blade_percent'], $row['blade_attempts'], 'bg-slate-500/50'], ['Mallet', $row['mallet_percent'], $row['mallet_attempts'], 'bg-emerald-500/40']] as [$label, $percent, $attempts, $colour])
                                <div class="flex items-center gap-2">
                                    <span class="w-12 shrink-0 text-[10px] text-slate-600">{{ $label }}</span>
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
                @foreach (Putter::cases() as $option)
                    <div class="rounded-lg bg-slate-900 p-3">
                        <div class="text-xs font-medium text-slate-200">{{ $option->label() }}</div>

                        @forelse ($strengths[$option->value] as $strength)
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
@endsection
