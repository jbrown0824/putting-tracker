@php
    /** @var \App\Enums\PuttContext|null $context The active context, null for both. */
    $target = request()->routeIs('stats.compare') ? 'stats.compare' : 'stats';

    /**
     * Carry the putter across so switching context does not silently reset it.
     * The compare view spans both putters and never defines one.
     */
    $carry = array_filter(['putter' => ($putter ?? null)?->value]);
@endphp

<div class="mt-1.5 grid grid-cols-3 gap-1.5">
    {{-- Explicitly "all" rather than an absent parameter, which the sticky filter would read as "no opinion". --}}
    <a href="{{ route($target, $carry + ['context' => \App\Enums\PuttContext::ANY]) }}"
       class="rounded-lg py-1.5 text-center text-xs transition {{ $context === null ? 'bg-sky-500/20 text-sky-200 ring-1 ring-sky-500/50' : 'border border-slate-800 text-slate-500' }}">
        Both
    </a>

    @foreach (\App\Enums\PuttContext::cases() as $option)
        <a href="{{ route($target, $carry + ['context' => $option->value]) }}"
           class="rounded-lg py-1.5 text-center text-xs transition {{ $context === $option ? 'bg-sky-500/20 text-sky-200 ring-1 ring-sky-500/50' : 'border border-slate-800 text-slate-500' }}">
            {{ $option->label() }}
        </a>
    @endforeach
</div>
