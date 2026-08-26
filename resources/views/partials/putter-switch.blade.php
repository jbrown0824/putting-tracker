@php
    /** @var \App\Enums\Putter|null $putter The active putter, or null on the compare view. */
    $comparing = request()->routeIs('stats.compare');

    /** Carry the context across so switching putter does not silently reset it. */
    $carry = array_filter(['context' => $context?->value]);
@endphp

<div class="mt-3 grid grid-cols-3 gap-1.5">
    @foreach (\App\Enums\Putter::cases() as $option)
        <a href="{{ route('stats', $carry + ['putter' => $option->value]) }}"
           class="rounded-lg py-2 text-center text-sm font-medium transition {{ ! $comparing && ($putter ?? null) === $option ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-400' }}">
            {{ $option->label() }}
        </a>
    @endforeach

    <a href="{{ route('stats.compare', $carry) }}"
       class="rounded-lg py-2 text-center text-sm font-medium transition {{ $comparing ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-400' }}">
        Compare
    </a>
</div>
