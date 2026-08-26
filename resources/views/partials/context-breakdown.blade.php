@php
    use App\Enums\Putter;

    /** @var array<string, array<string, mixed>> $contextBreakdown */
    $anyData = collect($contextBreakdown)
        ->contains(fn (array $row): bool => $row['inside']['attempts'] > 0 || $row['outside']['attempts'] > 0);
@endphp

@if ($anyData)
    <section class="mt-6">
        <h2 class="text-sm font-medium text-slate-300">Carpet vs. real greens</h2>
        <p class="mt-1 text-[11px] text-slate-500">What each putter gives up when you leave the carpet. Always spans both putters and both contexts, whatever the filters above say.</p>

        <div class="mt-2 overflow-hidden rounded-lg bg-slate-900">
            <div class="grid grid-cols-4 border-b border-slate-800 px-3 py-2 text-[11px] text-slate-500">
                <span></span>
                <span class="text-right">Inside</span>
                <span class="text-right">Outside</span>
                <span class="text-right">Drop</span>
            </div>

            @foreach (Putter::cases() as $option)
                @php $row = $contextBreakdown[$option->value]; @endphp
                <div class="grid grid-cols-4 items-baseline px-3 py-2.5 {{ ! $loop->last ? 'border-b border-slate-800/60' : '' }}">
                    <span class="text-xs font-medium text-slate-200">{{ $option->label() }}</span>

                    @foreach (['inside', 'outside'] as $side)
                        <span class="text-right text-sm {{ $row[$side]['attempts'] > 0 ? 'text-slate-300' : 'text-slate-700' }}">
                            @if ($row[$side]['attempts'] > 0)
                                {{ $row[$side]['make_percent'] }}%
                                <span class="block text-[10px] text-slate-600">n={{ $row[$side]['attempts'] }}</span>
                            @else
                                —
                            @endif
                        </span>
                    @endforeach

                    <span class="text-right text-sm">
                        @if ($row['comparable'])
                            <span class="{{ $row['drop'] > 0 ? 'text-amber-400' : 'text-emerald-400' }}">
                                {{ $row['drop'] > 0 ? '−' : '+' }}{{ abs($row['drop']) }}
                            </span>
                            <span class="block text-[10px] text-slate-600">points</span>
                        @else
                            <span class="text-slate-700">—</span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>

        @php
            $comparable = collect($contextBreakdown)->filter(fn (array $row): bool => $row['comparable']);
            $steadiest = $comparable->sortBy('drop')->first();
        @endphp

        @if ($comparable->count() === 2)
            <p class="mt-1.5 text-[11px] leading-relaxed text-slate-500">
                The {{ strtolower($steadiest['putter']->label()) }} travels best:
                @if ($steadiest['drop'] > 0)
                    it gives up only {{ $steadiest['drop'] }} points outside.
                @else
                    it actually makes {{ abs($steadiest['drop']) }} points more outside than in.
                @endif
            </p>
        @elseif ($comparable->isEmpty())
            <p class="mt-1.5 text-[11px] text-slate-500">Log putts both inside and outside with a putter to see what the greens cost you.</p>
        @endif
    </section>
@endif
