@extends('layouts.app')

@section('title', 'Session')

@section('content')
    <div class="flex items-center justify-between pt-2">
        <h1 class="text-lg font-medium">{{ $session->started_at->tz(auth()->user()->timezone)->format('M j, g:ia') }}</h1>
        <form method="POST" action="{{ route('sessions.destroy', $session) }}"
              onsubmit="return confirm('Delete this session and all its putts?')">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-xs text-rose-400">Delete session</button>
        </form>
    </div>

    <p class="mt-1 text-[11px] text-slate-500">
        {{ $session->whereLabel() }} · {{ $session->putter->name }}
        @if ($session->location) · {{ $session->location }} @endif
    </p>

    <a href="{{ route('stats', ['session' => $session]) }}" class="mt-2 inline-block text-xs text-emerald-400">
        Stats for this session ›
    </a>

    @if (session('status'))
        <p class="mt-2 rounded bg-emerald-500/10 px-3 py-2 text-xs text-emerald-300">{{ session('status') }}</p>
    @endif

    <div class="mt-3 divide-y divide-slate-800 rounded-lg bg-slate-900">
        @foreach ($session->putts as $putt)
            <div class="flex items-center justify-between px-3 py-2.5">
                <div class="flex items-center gap-2">
                    <span class="w-11 text-sm">{{ $putt->distance_ft }}ft</span>
                    <span class="rounded px-2 py-0.5 text-[11px] {{ $putt->result->isMade() ? 'bg-emerald-500/15 text-emerald-300' : 'bg-slate-800 text-slate-400' }}">
                        {{ $putt->result->label() }}
                    </span>
                    @if ($putt->slope)
                        <span class="text-[10px] text-slate-600">{{ $putt->slope->label() }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-[10px] text-slate-600">{{ $putt->hit_at->tz(auth()->user()->timezone)->format('g:ia') }}</span>
                    <form method="POST" action="{{ route('sessions.putts.destroy', [$session, $putt]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs text-slate-600">✕</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endsection
