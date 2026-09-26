@extends('layouts.app')

@section('title', 'Log putts')

@php
    use App\Enums\ClockPosition;

    $ladder = [2, 3, 4, 5, 6, 8, 10, 12, 15, 20, 25, 30, 40];

    /** Handed to Alpine so the enum stays the only place the ring order and the
        labels are defined. */
    $clock = [
        'ring' => array_map(fn (ClockPosition $p): string => $p->value, ClockPosition::ring()),
        'labels' => collect(ClockPosition::cases())
            ->mapWithKeys(fn (ClockPosition $p): array => [
                $p->value => ['clock' => $p->clockLabel(), 'detail' => $p->label()],
            ])
            ->all(),
    ];

    /** Everything the logger needs to know about this player's bag and surfaces. */
    $setup = [
        'userId' => $user->id,
        'putters' => $putters->map(fn ($putter): array => ['id' => $putter->id, 'name' => $putter->name])->values()->all(),
        'defaultPutterId' => $defaultPutter->id,
        'surfaceTypes' => collect(\App\Enums\PuttContext::cases())
            ->mapWithKeys(fn ($context): array => [$context->value => array_map(
                fn ($type): array => ['value' => $type->value, 'label' => $type->label()],
                \App\Enums\SurfaceType::forContext($context),
            )])
            ->all(),
    ];
@endphp

@section('content')
    <div x-data="puttTracker(@js($progress), @js($ladder), @js($clock), @js($setup))" x-cloak>
        <div class="flex items-baseline justify-between text-xs text-slate-400">
            <span>Today <span class="font-medium text-slate-100" x-text="progress.today.total"></span></span>
            <span>This week <span class="font-medium text-slate-100" x-text="progress.week.total"></span></span>
            <button type="button" @click="toggleWakeLock()"
                    class="rounded px-2 py-1 text-xs"
                    :class="awake ? 'bg-emerald-500/20 text-emerald-300' : 'text-slate-500'"
                    x-show="wakeLockSupported">
                <span x-text="awake ? 'Awake' : 'Keep awake'"></span>
            </button>
        </div>

        <div class="mt-1 flex justify-between text-[11px] text-slate-500">
            <span x-show="pending > 0" x-text="`${pending} unsynced`" class="text-amber-400"></span>
            <span x-show="pending === 0">&nbsp;</span>
        </div>

        {{-- Sync stopped because the login lapsed. The queue is kept, never dropped. --}}
        <a href="{{ route('login') }}" x-show="signedOut" x-cloak
           class="mt-2 block rounded-lg bg-amber-500/10 px-3 py-2 text-xs text-amber-300 ring-1 ring-amber-500/40">
            You've been signed out. <span class="underline">Log in</span> to sync <span x-text="pending"></span> saved putts.
        </a>

        {{-- The focused challenge: its goals, or for a drill, where to putt from next. --}}
        <template x-if="focused">
            <div class="mt-2 rounded-lg bg-slate-900 p-3 ring-1"
                 :class="countsFor(focused) ? 'ring-emerald-500/30' : 'ring-amber-500/40'">
                <div class="flex items-start justify-between gap-2">
                    <a :href="`{{ url('challenges') }}/${focused.id}`" class="min-w-0 truncate text-sm font-medium text-slate-100" x-text="focused.name"></a>
                    <button type="button" @click="unfocus()" class="shrink-0 text-[11px] text-slate-500" aria-label="Stop focusing">Unfocus ✕</button>
                </div>

                <template x-if="drillStep">
                    <div class="mt-2 flex items-center justify-between gap-2">
                        <div>
                            <div class="text-2xl font-medium text-slate-50">
                                <span x-text="drillStep.distance"></span><span class="text-sm text-slate-400"> ft</span>
                                <span class="text-sm text-sky-300" x-show="drillStep.clock" x-text="`· ${drillStep.clock}`"></span>
                            </div>
                            <div class="text-[11px] text-slate-400">
                                Step <span x-text="drillStep.number"></span> of <span x-text="drillStep.total"></span>
                                <span x-show="drillStep.rounds > 1" x-text="`· round ${drillStep.round} of ${drillStep.rounds}`"></span>
                                · make <span x-text="drillStep.needed"></span> more
                                · <span x-text="drillStep.attempts"></span> putts
                            </div>
                        </div>
                        <button type="button" @click="restartDrill()" class="shrink-0 rounded border border-slate-700 px-2 py-1 text-[11px] text-slate-400">Restart</button>
                    </div>
                </template>

                <template x-for="goal in focusedGoals" :key="goal.id">
                    <div class="mt-1.5 text-xs" :class="goal.done ? 'text-emerald-300' : 'text-slate-300'">
                        <div class="text-[10px] uppercase tracking-wide text-slate-500" x-text="goal.label"></div>
                        <div x-text="goal.line"></div>
                    </div>
                </template>

                <p x-show="! countsFor(focused)" class="mt-1.5 text-[11px] text-amber-300">This setup doesn't count toward this challenge.</p>
            </div>
        </template>

        <div x-show="celebration" x-transition class="mt-2 rounded-lg bg-emerald-500/15 px-3 py-2 text-sm text-emerald-200" x-text="celebration"></div>

        {{-- Everything running today. A lit dot means the next putt counts toward it; tap to focus. --}}
        <div x-show="! focused && challenges.length > 0" class="-mx-4 mt-2 flex gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none]">
            <template x-for="challenge in challenges" :key="challenge.id">
                <button type="button" @click="focus(challenge.id)"
                        class="flex shrink-0 items-center gap-1.5 rounded-full border border-slate-800 px-2.5 py-1 text-[11px] text-slate-400">
                    <span class="size-1.5 rounded-full" :class="countsFor(challenge) ? 'bg-emerald-400' : 'bg-slate-700'"></span>
                    <span x-text="challenge.name"></span>
                </button>
            </template>
        </div>

        {{-- Scrolls sideways rather than wrapping, so a full bag never pushes the dial down. --}}
        <div class="-mx-4 mt-3 flex gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none]">
            <template x-for="option in putters.filter((p) => ! putterLocked || p.id === putterId)" :key="option.id">
                <button type="button" @click="setPutter(option.id)"
                        class="shrink-0 rounded-lg px-3 py-2 text-xs font-medium transition"
                        :class="putterId === option.id ? 'bg-sky-500/20 text-sky-200 ring-1 ring-sky-500/50' : 'border border-slate-800 text-slate-500'"
                        x-text="option.name"></button>
            </template>
            <a href="{{ route('putters.create') }}" x-show="! putterLocked"
               class="shrink-0 rounded-lg border border-dashed border-slate-800 px-3 py-2 text-xs text-slate-600">+ Putter</a>
        </div>

        <div class="mt-2 grid grid-cols-2 gap-2">
            <button type="button" @click="setContext('inside')" :disabled="contextLocked"
                    class="rounded-lg py-3 text-sm font-medium transition"
                    :class="context === 'inside' ? 'bg-slate-100 text-slate-900' : (contextLocked ? 'border border-slate-900 text-slate-700' : 'border border-slate-700 text-slate-400')">
                Inside
            </button>
            <button type="button" @click="setContext('outside')" :disabled="contextLocked"
                    class="rounded-lg py-3 text-sm font-medium transition"
                    :class="context === 'outside' ? 'bg-slate-100 text-slate-900' : (contextLocked ? 'border border-slate-900 text-slate-700' : 'border border-slate-700 text-slate-400')">
                Outside
            </button>
        </div>

        {{-- Optional detail on top of inside/outside. Tapping the chosen one clears it. --}}
        <div class="mt-1.5 flex flex-wrap gap-1.5">
            <template x-for="type in surfaceOptions.filter((t) => ! surfaceLocked || t.value === surfaceType)" :key="type.value">
                <button type="button" @click="setSurfaceType(type.value)"
                        class="rounded-md px-2.5 py-1 text-[11px] transition"
                        :class="surfaceType === type.value ? 'bg-slate-700 text-slate-100' : 'border border-slate-800 text-slate-500'"
                        x-text="type.label"></button>
            </template>
        </div>

        {{-- Where you are standing, not a property of the individual putt: set it
             once and it rides along with every putt until you move. The arrows
             walk round the hole for an around-the-world drill. --}}
        <div class="mt-2 flex items-center gap-1.5">
            <button type="button" @click="stepClockPosition(-1)" aria-label="Previous position"
                    class="flex h-10 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-800 text-slate-400 active:bg-slate-800">‹</button>

            <button type="button" @click="ringOpen = ! ringOpen"
                    class="flex h-10 flex-1 flex-col items-center justify-center rounded-lg transition"
                    :class="clockPosition ? 'border border-slate-800' : 'bg-amber-500/10 ring-1 ring-amber-500/40'">
                <span class="text-xs font-medium"
                      :class="clockPosition ? 'text-slate-200' : 'text-amber-300'"
                      x-text="clockPosition ? clockLabel : 'Tap to set position'"></span>
                {{-- Flat's two labels are the same word, so the second line would
                     just repeat it. --}}
                <span class="text-[10px] text-slate-500"
                      x-show="clockPosition && clockDetail !== clockLabel"
                      x-text="clockDetail"></span>
            </button>

            <button type="button" @click="stepClockPosition(1)" aria-label="Next position"
                    class="flex h-10 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-800 text-slate-400 active:bg-slate-800">›</button>
        </div>

        @include('partials.clock-ring')

        <div class="mt-4 flex items-center justify-between">
            <button type="button" @click="stepDistance(-1)" aria-label="Shorter" :class="distanceLocked && 'invisible'"
                    class="flex h-14 w-14 items-center justify-center rounded-full border border-slate-700 text-2xl text-slate-300 active:bg-slate-800">−</button>

            <div class="text-center">
                <input type="number" inputmode="numeric" :value="distance" @change="setDistance($event.target.value)" :readonly="distanceLocked"
                       aria-label="Distance in feet"
                       class="w-24 bg-transparent text-center text-3xl font-medium text-slate-50 focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none">
                <div class="text-[11px] text-slate-500" x-text="distanceLocked ? 'feet · set by the challenge' : 'feet · tap to edit'"></div>
            </div>

            <button type="button" @click="stepDistance(1)" aria-label="Longer" :class="distanceLocked && 'invisible'"
                    class="flex h-14 w-14 items-center justify-center rounded-full border border-slate-700 text-2xl text-slate-300 active:bg-slate-800">+</button>
        </div>

        {{-- Dims and stops accepting taps for half a second after each putt, so a
             logged putt is unmistakable and a double tap cannot log a phantom. --}}
        <div class="relative mt-4 flex justify-center transition-opacity duration-150"
             :class="locked ? 'pointer-events-none opacity-50' : ''"
             :aria-busy="locked">
            <svg viewBox="0 0 240 240" class="w-full max-w-[300px] touch-manipulation" role="group" aria-label="Putt result">
                <path d="M120 120 L38.7 38.7 A115 115 0 0 1 201.3 38.7 Z" class="transition-colors"
                      :class="flash === 'miss_long' ? 'fill-slate-400' : 'fill-slate-800 active:fill-slate-700'"
                      @click="record('miss_long')" role="button" aria-label="Missed long"/>
                <path d="M120 120 L201.3 201.3 A115 115 0 0 1 38.7 201.3 Z" class="transition-colors"
                      :class="flash === 'miss_short' ? 'fill-slate-400' : 'fill-slate-800 active:fill-slate-700'"
                      @click="record('miss_short')" role="button" aria-label="Missed short"/>

                {{-- Simple: one wedge a side. --}}
                <g x-show="! advancedMisses">
                    <path d="M120 120 L201.3 38.7 A115 115 0 0 1 201.3 201.3 Z" class="transition-colors"
                          :class="flash === 'miss_right' ? 'fill-slate-400' : 'fill-slate-800 active:fill-slate-700'"
                          @click="record('miss_right')" role="button" aria-label="Missed right"/>
                    <path d="M120 120 L38.7 201.3 A115 115 0 0 1 38.7 38.7 Z" class="transition-colors"
                          :class="flash === 'miss_left' ? 'fill-slate-400' : 'fill-slate-800 active:fill-slate-700'"
                          @click="record('miss_left')" role="button" aria-label="Missed left"/>
                </g>

                {{-- Advanced: each side splits at r=84 into an inner stroke band and an
                     outer read band, so classifying still costs exactly one tap. --}}
                <g x-show="advancedMisses">
                    <path d="M120 120 L179.4 60.6 A84 84 0 0 1 179.4 179.4 Z" class="transition-colors"
                          :class="flash === 'miss_right' && flashCause === 'stroke' ? 'fill-slate-400' : 'fill-slate-800 active:fill-slate-700'"
                          @click="record('miss_right', 'stroke')" role="button" aria-label="Pushed it right"/>
                    <path d="M179.4 60.6 L201.3 38.7 A115 115 0 0 1 201.3 201.3 L179.4 179.4 A84 84 0 0 0 179.4 60.6 Z"
                          class="transition-colors"
                          :class="flash === 'miss_right' && flashCause === 'read' ? 'fill-sky-400' : 'fill-slate-800 active:fill-slate-700'"
                          @click="record('miss_right', 'read')" role="button" aria-label="Misread the break right"/>

                    <path d="M120 120 L60.6 179.4 A84 84 0 0 1 60.6 60.6 Z" class="transition-colors"
                          :class="flash === 'miss_left' && flashCause === 'stroke' ? 'fill-slate-400' : 'fill-slate-800 active:fill-slate-700'"
                          @click="record('miss_left', 'stroke')" role="button" aria-label="Pulled it left"/>
                    <path d="M60.6 179.4 L38.7 201.3 A115 115 0 0 1 38.7 38.7 L60.6 60.6 A84 84 0 0 0 60.6 179.4 Z"
                          class="transition-colors"
                          :class="flash === 'miss_left' && flashCause === 'read' ? 'fill-sky-400' : 'fill-slate-800 active:fill-slate-700'"
                          @click="record('miss_left', 'read')" role="button" aria-label="Misread the break left"/>

                    <path d="M60.6 179.4 A84 84 0 0 1 60.6 60.6" fill="none" class="stroke-slate-950" stroke-width="2"/>
                    <path d="M179.4 60.6 A84 84 0 0 1 179.4 179.4" fill="none" class="stroke-slate-950" stroke-width="2"/>
                </g>

                <line x1="38.7" y1="38.7" x2="201.3" y2="201.3" class="stroke-slate-950" stroke-width="2"/>
                <line x1="201.3" y1="38.7" x2="38.7" y2="201.3" class="stroke-slate-950" stroke-width="2"/>

                <text x="120" y="34" text-anchor="middle" class="fill-slate-400 text-[13px]">LONG</text>
                <text x="120" y="214" text-anchor="middle" class="fill-slate-400 text-[13px]">SHORT</text>

                <g x-show="! advancedMisses">
                    <text x="26" y="125" text-anchor="middle" class="fill-slate-400 text-[13px]">LEFT</text>
                    <text x="214" y="125" text-anchor="middle" class="fill-slate-400 text-[13px]">RIGHT</text>
                </g>

                <g x-show="advancedMisses">
                    <text x="20.5" y="123" text-anchor="middle" class="fill-sky-300 text-[9px]">READ</text>
                    <text x="51" y="123" text-anchor="middle" class="fill-slate-400 text-[9px]">PULL</text>
                    <text x="189" y="123" text-anchor="middle" class="fill-slate-400 text-[9px]">PUSH</text>
                    <text x="219.5" y="123" text-anchor="middle" class="fill-sky-300 text-[9px]">READ</text>
                </g>

                <circle cx="120" cy="120" r="54" class="fill-slate-950"/>
                <circle cx="120" cy="120" r="50" @click="record('sunk')" role="button" aria-label="Sunk"
                        class="stroke-emerald-500 transition-colors" stroke-width="3"
                        :class="flash === 'sunk' ? 'fill-emerald-500/70' : 'fill-emerald-500/15 active:fill-emerald-500/40'"/>
                <text x="120" y="127" text-anchor="middle" class="pointer-events-none fill-emerald-300 text-[20px] font-medium">SUNK</text>
            </svg>

            <button type="button" @click="record('lip_out')"
                    class="absolute bottom-0 right-0 flex flex-col items-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full border text-lg transition-colors"
                      :class="flash === 'lip_out' ? 'border-slate-400 bg-slate-600 text-slate-50' : 'border-slate-700 bg-slate-900 text-slate-300 active:bg-slate-800'">◠</span>
                <span class="mt-0.5 text-[10px] text-slate-500">Lip out</span>
            </button>
        </div>

        <div class="mt-3 text-center text-xs text-slate-400">
            <template x-if="sessionCount > 0">
                <span><span x-text="putterName"></span> this session · <span x-text="sessionCount"></span> putts · <span x-text="sessionSunk"></span> sunk (<span x-text="sessionPercent"></span>%)</span>
            </template>
            <template x-if="sessionCount === 0">
                <span>Tap the dial to log your first putt.</span>
            </template>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-center gap-1.5">
            <template x-for="putt in recent.slice(0, 6)" :key="putt.uuid">
                <span class="rounded px-2 py-1 text-[11px]"
                      :class="putt.result === 'sunk' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-slate-800 text-slate-400'"
                      x-text="`${putt.distance_ft}ft ${putt.result.replace('miss_', '').replace('_', ' ')}`"></span>
            </template>
            <button type="button" @click="undo()" x-show="recent.length > 0"
                    class="rounded border border-slate-700 px-2 py-1 text-[11px] text-slate-400 active:bg-slate-800">Undo</button>
        </div>

        <div class="mt-4 border-t border-slate-800 pt-3">
            <button type="button" @click="advancedOpen = !advancedOpen"
                    class="flex w-full items-center justify-between text-sm text-slate-400">
                <span>Advanced</span>
                <span x-text="advancedOpen ? '−' : '+'"></span>
            </button>

            <div x-show="advancedOpen" class="mt-3 space-y-3 pb-4">
                <div>
                    <label class="text-[11px] uppercase tracking-wide text-slate-500">Line misses</label>
                    <div class="mt-1 grid grid-cols-2 gap-1.5">
                        <button type="button" @click="advancedMisses && toggleAdvancedMisses()"
                                class="rounded py-2 text-xs"
                                :class="! advancedMisses ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-400'">
                            Left / right
                        </button>
                        <button type="button" @click="! advancedMisses && toggleAdvancedMisses()"
                                class="rounded py-2 text-xs"
                                :class="advancedMisses ? 'bg-sky-500/20 text-sky-200 ring-1 ring-sky-500/50' : 'border border-slate-700 text-slate-400'">
                            Split pull / read
                        </button>
                    </div>
                    <p class="mt-1 text-[10px] leading-relaxed text-slate-600">
                        Splits each side of the dial into an inner band for a pull or push and an
                        outer band for a misread break. Still one tap.
                    </p>
                </div>

                <input type="text" x-model="location" @change="savePrefs()" placeholder="Location"
                       class="w-full rounded border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none">
            </div>
        </div>
    </div>
@endsection
