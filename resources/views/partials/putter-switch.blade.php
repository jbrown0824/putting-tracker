@php
    /** @var \App\Models\Putter|null $putter The active putter; null means all putters, or the compare view. */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Putter> $putters */
    $comparing = request()->routeIs('stats.compare');

    /** Carry the context across so switching putter does not silently reset it. */
    $carry = array_filter(['context' => $context?->value]);

    $chip = fn (bool $active): string => $active
        ? 'bg-slate-100 text-slate-900'
        : 'border border-slate-700 text-slate-400';
@endphp

{{-- Scrolls sideways rather than wrapping, so a full bag never pushes the page down. --}}
<div class="-mx-4 mt-3 flex gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none]">
    <a href="{{ route('stats', $carry + ['putter' => \App\Http\Controllers\StatsController::ALL_PUTTERS]) }}"
       class="shrink-0 rounded-lg px-3 py-2 text-center text-sm font-medium transition {{ $chip(! $comparing && ($putter ?? null) === null) }}">
        All
    </a>

    @foreach ($putters as $option)
        <a href="{{ route('stats', $carry + ['putter' => $option->id]) }}"
           class="shrink-0 rounded-lg px-3 py-2 text-center text-sm font-medium transition {{ $chip(! $comparing && ($putter ?? null)?->is($option)) }} {{ $option->isRetired() ? 'opacity-60' : '' }}">
            {{ $option->name }}
        </a>
    @endforeach

    <a href="{{ route('stats.compare', $carry) }}"
       class="shrink-0 rounded-lg px-3 py-2 text-center text-sm font-medium transition {{ $chip($comparing) }}">
        Compare
    </a>
</div>
