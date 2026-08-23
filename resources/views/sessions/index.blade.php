@extends('layouts.app')

@section('title', 'History')

@section('content')
    <h1 class="pt-2 text-lg font-medium">History</h1>

    @if (session('status'))
        <p class="mt-2 rounded bg-emerald-500/10 px-3 py-2 text-xs text-emerald-300">{{ session('status') }}</p>
    @endif

    @forelse ($sessions as $session)
        <a href="{{ route('sessions.show', $session) }}"
           class="mt-2 flex items-center justify-between rounded-lg bg-slate-900 px-3 py-3">
            <div>
                <div class="text-sm">
                    {{ $session->started_at->format('M j, g:ia') }}
                    <span class="ml-1 rounded px-1.5 py-0.5 text-[10px] {{ $session->context->value === 'outside' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-slate-700 text-slate-300' }}">
                        {{ $session->context->label() }}
                    </span>
                </div>
                <div class="mt-0.5 text-[11px] text-slate-500">
                    {{ $session->putts_count }} putts · {{ $session->sunk_count }} sunk
                    ({{ $session->putts_count > 0 ? round($session->sunk_count / $session->putts_count * 100) : 0 }}%)
                    @if ($session->location) · {{ $session->location }} @endif
                </div>
            </div>
            <span class="text-slate-600">›</span>
        </a>
    @empty
        <p class="mt-4 rounded-lg bg-slate-900 p-4 text-sm text-slate-400">No sessions yet.</p>
    @endforelse

    <div class="mt-4">{{ $sessions->links() }}</div>
@endsection
