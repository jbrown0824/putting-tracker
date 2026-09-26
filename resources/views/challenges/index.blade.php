@extends('layouts.app')

@section('title', 'Challenges')

@section('content')
    <div class="flex items-center justify-between pt-2">
        <h1 class="text-lg font-medium">Challenges</h1>
        <a href="{{ route('challenges.create') }}" class="rounded-lg bg-emerald-500 px-3 py-1.5 text-xs font-medium text-slate-950">+ New</a>
    </div>

    @if (session('status'))
        <p class="mt-2 rounded bg-emerald-500/10 px-3 py-2 text-xs text-emerald-300">{{ session('status') }}</p>
    @endif

    @if ($active->isEmpty() && $upcoming->isEmpty() && $finished->isEmpty())
        <div class="mt-6 rounded-lg bg-slate-900 p-4 text-sm text-slate-400">
            No challenges yet. Set a volume target, a daily habit, or a guided ladder drill — you can run as many at once as you like, and every putt counts towards each one it fits.
        </div>
    @endif

    @foreach (['Running' => $active, 'Coming up' => $upcoming, 'Finished' => $finished] as $heading => $rows)
        @if ($rows->isNotEmpty())
            <h2 class="mt-5 text-[11px] uppercase tracking-wide text-slate-500">{{ $heading }}</h2>
            <div class="mt-1.5 space-y-2">
                @foreach ($rows as $row)
                    @php
                        $challenge = $row['challenge'];
                        $lead = $row['goals'][0] ?? null;
                    @endphp
                    <a href="{{ route('challenges.show', $challenge) }}" class="block rounded-lg bg-slate-900 p-3 {{ $heading === 'Finished' ? 'opacity-70' : '' }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate text-sm text-slate-100">
                                    {{ $challenge->name }}
                                    @if ($challenge->isDrill())
                                        <span class="ml-1 rounded bg-violet-500/15 px-1.5 py-0.5 text-[10px] text-violet-300">Drill</span>
                                    @endif
                                </div>
                                <div class="mt-0.5 truncate text-[11px] text-slate-500">
                                    @if ($lead)
                                        {{ ucfirst($lead['label']) }}
                                    @elseif ($challenge->isDrill())
                                        {{ $challenge->steps->count() }} steps · finished {{ $row['drill']['completed_runs'] }} {{ Str::plural('time', $row['drill']['completed_runs']) }}
                                    @endif
                                </div>
                            </div>
                            @if ($lead)
                                @include('partials.goal-status', ['status' => $lead['status']])
                            @endif
                        </div>

                        @if ($lead && $row['state'] === 'active')
                            @php
                                $fill = $lead['current'] !== null
                                    ? ($lead['target'] > 0 ? min(100, $lead['current']['value'] / $lead['target'] * 100) : 0)
                                    : ($lead['percent'] ?? 0);
                            @endphp
                            <div class="mt-2 flex items-center gap-2">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-800">
                                    <div class="h-full bg-emerald-500" style="width: {{ $fill }}%"></div>
                                </div>
                                <span class="shrink-0 text-[11px] text-slate-400">
                                    @if ($lead['current'] !== null)
                                        {{ $lead['current']['label'] }} {{ $lead['current']['value'] }}/{{ $lead['target'] }}
                                    @else
                                        {{ number_format($lead['value']) }}/{{ number_format($lead['target']) }}
                                    @endif
                                </span>
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    @endforeach
@endsection
