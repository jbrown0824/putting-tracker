@php
    /** @var string $status */
    [$text, $classes] = match ($status) {
        'complete' => ['Done', 'bg-emerald-500/15 text-emerald-300'],
        'behind' => ['Behind pace', 'bg-amber-500/15 text-amber-300'],
        'failed' => ['Missed', 'bg-rose-500/15 text-rose-300'],
        'upcoming' => ['Not started', 'bg-slate-700 text-slate-300'],
        default => ['On track', 'bg-sky-500/15 text-sky-300'],
    };
@endphp
<span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] {{ $classes }}">{{ $text }}</span>
