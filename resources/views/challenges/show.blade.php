@extends('layouts.app')

@php
    $challenge = $progress['challenge'];
    $formatValue = fn ($value, $goal): string => $goal->metric === \App\Enums\GoalMetric::MakePercent ? $value.'%' : number_format($value);
@endphp

@section('title', $challenge->name)

@section('content')
    <div class="flex items-start justify-between gap-2 pt-2">
        <div class="min-w-0">
            <h1 class="text-lg font-medium">{{ $challenge->name }}</h1>
            <p class="mt-0.5 text-[11px] text-slate-500">
                {{ $challenge->starts_on->format('M j') }}{{ $challenge->ends_on ? ' – '.$challenge->ends_on->format('M j, Y') : ' onwards' }}
                @if ($progress['state'] === 'active' && $progress['days_remaining'] !== null)
                    · {{ $progress['days_remaining'] }} {{ Str::plural('day', $progress['days_remaining']) }} left
                @elseif ($progress['state'] === 'upcoming')
                    · starts in {{ (int) now(auth()->user()->timezone)->startOfDay()->diffInDays($challenge->starts_on->toDateString()) }} days
                @endif
            </p>
            <p class="mt-0.5 text-[11px] text-slate-500">{{ $challenge->describeFilters() }}</p>
        </div>
        <a href="{{ route('challenges.edit', $challenge) }}" class="shrink-0 text-xs text-slate-400">Edit</a>
    </div>

    @if (session('status'))
        <p class="mt-2 rounded bg-emerald-500/10 px-3 py-2 text-xs text-emerald-300">{{ session('status') }}</p>
    @endif

    @if ($challenge->description)
        <p class="mt-2 text-xs leading-relaxed text-slate-400">{{ $challenge->description }}</p>
    @endif

    @if ($progress['state'] !== 'ended' && $challenge->archived_at === null)
        {{-- Focus lives in this browser only, so it is set here and read by the logger. --}}
        <button type="button" x-data="focusChallenge({{ auth()->id() }}, {{ $challenge->id }}, @js(route('log')))" @click="go()"
                class="mt-3 w-full rounded-lg bg-emerald-500 py-3 text-sm font-medium text-slate-950">
            {{ $challenge->isDrill() ? 'Start the drill' : 'Focus in the logger' }}
        </button>
    @endif

    @if ($challenge->isDrill())
        @php $drill = $progress['drill']; @endphp
        <section class="mt-5">
            <h2 class="text-sm font-medium text-slate-300">Drill</h2>
            <div class="mt-2 grid grid-cols-3 gap-2">
                @foreach ([
                    ['Finished', $drill['completed_runs']],
                    ['Attempts', $drill['started_runs']],
                    ['Best', $drill['fewest_putts'] !== null ? $drill['fewest_putts'].' putts' : '—'],
                ] as [$label, $value])
                    <div class="rounded-lg bg-slate-900 p-3">
                        <div class="text-[11px] text-slate-500">{{ $label }}</div>
                        <div class="mt-0.5 text-base font-medium">{{ $value }}</div>
                    </div>
                @endforeach
            </div>

            <div class="mt-2 flex flex-wrap gap-1">
                @foreach ($challenge->steps as $step)
                    @php
                        $sunk = $challenge->sunkRequiredFor($step);
                        $attempts = $challenge->attemptsFor($step);
                        $requirement = match (true) {
                            $attempts > $sunk => " · {$sunk} of {$attempts}",
                            $sunk > 1 => " ×{$sunk}",
                            default => '',
                        };
                    @endphp
                    <span class="rounded bg-slate-800 px-2 py-1 text-[11px] text-slate-300">
                        {{ $step->distance_ft }}ft{{ $step->clock_position ? ' · '.$step->clock_position->clockLabel() : '' }}{{ $requirement }}
                    </span>
                @endforeach
            </div>
            <p class="mt-1.5 text-[11px] text-slate-500">
                {{ $challenge->drill_order?->label() }} ·
                miss: {{ strtolower($challenge->drill_on_miss?->label() ?? '') }}
                @if ($challenge->drill_rounds > 1) · {{ $challenge->drill_rounds }} rounds @endif
            </p>

            @if ($recentRuns->isNotEmpty())
                <div class="mt-3 divide-y divide-slate-800 rounded-lg bg-slate-900">
                    @foreach ($recentRuns as $run)
                        <div class="flex justify-between px-3 py-2 text-xs">
                            <span class="text-slate-400">{{ $run->started_at->tz(auth()->user()->timezone)->format('M j, g:ia') }}</span>
                            <span class="{{ $run->completed_at ? 'text-emerald-300' : 'text-slate-500' }}">
                                {{ $run->putts_count }} putts · {{ $run->completed_at ? 'finished' : 'unfinished' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    @if ($progress['goals'] !== [])
        <section class="mt-5 space-y-3 pb-2">
            <h2 class="text-sm font-medium text-slate-300">Goals</h2>

            @foreach ($progress['goals'] as $goal)
                @php $model = $goal['goal']; @endphp
                <div class="rounded-lg bg-slate-900 p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-sm text-slate-100">{{ ucfirst($goal['label']) }}</div>
                        @include('partials.goal-status', ['status' => $goal['status']])
                    </div>

                    @if ($goal['current'] !== null)
                        {{-- A daily or weekly goal: how far the open period is from its target. --}}
                        <div class="mt-2 flex items-baseline justify-between text-xs">
                            <span class="text-slate-400">{{ $goal['current']['label'] }}
                                <span class="text-base font-medium text-slate-100">{{ $formatValue($goal['current']['value'], $model) }}</span>
                                / {{ $formatValue($goal['target'], $model) }}</span>
                            <span class="{{ $goal['current']['met'] ? 'text-emerald-400' : 'text-slate-500' }}">
                                {{ $goal['current']['met'] ? 'Done' : $formatValue($goal['current']['remaining'], $model).' to go' }}
                                @if ($model->period === \App\Enums\GoalPeriod::Weekly && ! $goal['current']['met'])
                                    · {{ $goal['current']['days_left'] }} {{ Str::plural('day', $goal['current']['days_left']) }} left
                                @endif
                            </span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-800">
                            <div class="h-full {{ $goal['current']['met'] ? 'bg-emerald-500' : 'bg-sky-500' }}" style="width: {{ min(100, $goal['target'] > 0 ? $goal['current']['value'] / $goal['target'] * 100 : 0) }}%"></div>
                        </div>
                    @elseif ($model->period === \App\Enums\GoalPeriod::Total)
                        <div class="mt-2 flex items-baseline justify-between text-xs">
                            <span class="text-slate-400"><span class="text-base font-medium text-slate-100">{{ $formatValue($goal['value'], $model) }}</span> / {{ $formatValue($goal['target'], $model) }}</span>
                            <span class="text-slate-500">
                                @if ($goal['pace']['per_day_needed'] ?? null)
                                    {{ number_format($goal['pace']['per_day_needed']) }}/day to finish
                                @elseif (! $goal['met'])
                                    {{ $formatValue($goal['remaining'], $model) }} to go
                                @endif
                            </span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-800">
                            <div class="h-full {{ $goal['met'] ? 'bg-emerald-500' : 'bg-sky-500' }}" style="width: {{ $goal['percent'] }}%"></div>
                        </div>
                    @endif

                    @if ($goal['periods'] !== null)
                        @php $periods = $goal['periods']; $unit = $model->period === \App\Enums\GoalPeriod::Daily ? 'day' : 'week'; @endphp
                        <p class="mt-2 text-[11px] text-slate-500">
                            {{ $periods['met'] }} {{ Str::plural($unit, $periods['met']) }} hit{{ $periods['required'] !== null ? ' of '.$periods['required'].' needed' : '' }}
                            · {{ $periods['missed'] }} missed
                            @if ($periods['streak'] > 1) · {{ $periods['streak'] }}-{{ $unit }} streak @endif
                        </p>

                        @if ($model->period === \App\Enums\GoalPeriod::Daily)
                            <div class="mt-2 flex flex-wrap gap-1" aria-label="Each day of the challenge">
                                @foreach ($goal['series'] as $day)
                                    <span title="{{ $day['label'] }}: {{ $day['value'] ?? '—' }}"
                                          class="size-3 rounded-sm {{ $day['met'] === true ? 'bg-emerald-500' : ($day['value'] === null ? 'bg-slate-800/60' : ($day['date'] === now(auth()->user()->timezone)->toDateString() ? 'bg-sky-500/50' : 'bg-rose-500/40')) }}"></span>
                                @endforeach
                            </div>
                        @endif
                    @endif

                    @if ($model->period === \App\Enums\GoalPeriod::Total && in_array($model->metric, [\App\Enums\GoalMetric::Attempts, \App\Enums\GoalMetric::Makes], true))
                        @include('partials.pace-chart', ['series' => $goal['series']])
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    <div class="mt-6 grid grid-cols-2 gap-2 pb-4">
        <form method="POST" action="{{ route('challenges.archive', $challenge) }}">
            @csrf
            @method('PATCH')
            <button type="submit" class="w-full rounded-lg border border-slate-700 py-2 text-xs text-slate-400">
                {{ $challenge->archived_at ? 'Restore' : 'Archive' }}
            </button>
        </form>
        <form method="POST" action="{{ route('challenges.destroy', $challenge) }}" onsubmit="return confirm('Delete this challenge? Your putts are kept.')">
            @csrf
            @method('DELETE')
            <button type="submit" class="w-full rounded-lg py-2 text-xs text-rose-400">Delete</button>
        </form>
    </div>
@endsection
