@extends('layouts.app')

@section('title', $challenge->exists ? 'Edit challenge' : 'New challenge')

@php
    use App\Enums\ClockPosition;
    use App\Enums\DrillMissRule;
    use App\Enums\DrillOrder;
    use App\Enums\GoalMetric;
    use App\Enums\GoalPeriod;
    use App\Enums\PuttContext;
    use App\Enums\SurfaceType;

    $state = [
        'name' => $challenge->name ?? '',
        'description' => $challenge->description ?? '',
        'kind' => $challenge->kind->value,
        'starts_on' => $challenge->starts_on?->toDateString(),
        'ends_on' => $challenge->ends_on?->toDateString(),
        'putter_ids' => $challenge->exists ? $challenge->putters->modelKeys() : [],
        'contexts' => $challenge->contexts?->map->value->values()->all() ?? [],
        'surface_types' => $challenge->surface_types?->map->value->values()->all() ?? [],
        'min_distance_ft' => $challenge->min_distance_ft,
        'max_distance_ft' => $challenge->max_distance_ft,
        'goals' => $challenge->exists ? $challenge->goals->map(fn ($goal): array => [
            'metric' => $goal->metric->value,
            'period' => $goal->period->value,
            'target' => $goal->target,
            'context' => $goal->context?->value,
            'min_distance_ft' => $goal->min_distance_ft,
            'max_distance_ft' => $goal->max_distance_ft,
            'min_attempts' => $goal->min_attempts,
            'periods_required' => $goal->periods_required,
        ])->all() : [['metric' => 'attempts', 'period' => 'total', 'target' => 1000]],
        'steps' => $challenge->exists ? $challenge->steps->map(fn ($step): array => [
            'distance_ft' => $step->distance_ft,
            'clock_position' => $step->clock_position?->value,
            'makes_required' => $step->makes_required,
        ])->all() : [],
        'drill_on_miss' => $challenge->drill_on_miss?->value ?? DrillMissRule::Restart->value,
        'drill_order' => $challenge->drill_order?->value ?? DrillOrder::Sequential->value,
        'drill_makes_required' => $challenge->drill_makes_required ?? 1,
        'drill_attempts' => $challenge->drill_attempts ?? 1,
        'drill_rounds' => $challenge->drill_rounds ?? 1,
    ];

    // After a failed submit, show what was typed rather than what was saved.
    $state = array_merge($state, collect(session()->getOldInput())->only(array_keys($state))->all());

    $options = [
        'metrics' => collect(GoalMetric::cases())->map(fn ($case): array => ['value' => $case->value, 'label' => $case->label()])->all(),
        'periods' => collect(GoalPeriod::cases())->map(fn ($case): array => ['value' => $case->value, 'label' => $case->label()])->all(),
        'surfaceTypes' => collect(SurfaceType::cases())->map(fn ($case): array => ['value' => $case->value, 'label' => $case->label(), 'context' => $case->context()->value])->all(),
        'clock' => array_map(fn (ClockPosition $position): array => ['value' => $position->value, 'label' => $position->clockLabel()], ClockPosition::ring()),
        'today' => \Illuminate\Support\Carbon::now(auth()->user()->timezone)->toDateString(),
    ];

    $chip = "rounded-lg border px-3 py-1.5 text-xs transition";
    $label = "text-[11px] uppercase tracking-wide text-slate-500";
    $field = "rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-base text-slate-100 placeholder:text-slate-600 focus:border-slate-500 focus:outline-none";
    $input = "w-full ".$field;
@endphp

@section('content')
    <div class="flex items-center justify-between pt-2">
        <h1 class="text-lg font-medium">{{ $challenge->exists ? 'Edit challenge' : 'New challenge' }}</h1>
        <a href="{{ $challenge->exists ? route('challenges.show', $challenge) : route('challenges.index') }}" class="text-xs text-slate-500">Cancel</a>
    </div>

    @if ($errors->any())
        <div class="mt-3 rounded-lg bg-rose-500/10 px-3 py-2 text-xs text-rose-300">
            @foreach (array_unique($errors->all()) as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ $challenge->exists ? route('challenges.update', $challenge) : route('challenges.store') }}"
          x-data="challengeForm(@js($state), @js($options))" class="mt-4 space-y-6 pb-6">
        @csrf
        @if ($challenge->exists)
            @method('PUT')
        @endif

        @unless ($challenge->exists)
            <section>
                <div class="{{ $label }}">Start from</div>
                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    <template x-for="preset in presets" :key="preset.key">
                        <button type="button" @click="applyPreset(preset.key)"
                                class="{{ $chip }} border-slate-700 text-slate-300 active:bg-slate-800" x-text="preset.label"></button>
                    </template>
                </div>
            </section>
        @endunless

        <section class="space-y-3">
            <div>
                <label for="name" class="{{ $label }}">Name</label>
                <input id="name" name="name" x-model="name" required maxlength="80" class="mt-1 {{ $input }}" placeholder="Winter mat challenge">
            </div>

            <div>
                <span class="{{ $label }}">Type</span>
                <div class="mt-1 grid grid-cols-2 gap-1.5">
                    <input type="hidden" name="kind" :value="kind">
                    <button type="button" @click="kind = 'goals'" class="rounded-lg py-2 text-sm"
                            :class="kind === 'goals' ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-400'">Goals</button>
                    <button type="button" @click="kind = 'drill'" class="rounded-lg py-2 text-sm"
                            :class="kind === 'drill' ? 'bg-slate-100 text-slate-900' : 'border border-slate-700 text-slate-400'">Guided drill</button>
                </div>
                <p class="mt-1 text-[10px] leading-relaxed text-slate-600"
                   x-text="kind === 'goals' ? 'Log freely — every matching putt counts towards the goals.' : 'The logger tells you where to putt from, one step at a time.'"></p>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label for="starts_on" class="{{ $label }}">Starts</label>
                    <input id="starts_on" type="date" name="starts_on" x-model="starts_on" required class="mt-1 {{ $input }}">
                </div>
                <div>
                    <label for="ends_on" class="{{ $label }}">Ends</label>
                    <input id="ends_on" type="date" name="ends_on" x-model="ends_on" class="mt-1 {{ $input }}">
                </div>
            </div>
            <p class="-mt-1 text-[10px] text-slate-600">Leave the end blank for an open-ended challenge.</p>
        </section>

        <section class="space-y-3">
            <h2 class="text-sm font-medium text-slate-300">Which putts count</h2>

            <div>
                <span class="{{ $label }}">Putters</span>
                <div class="mt-1 flex flex-wrap gap-1.5">
                    <button type="button" @click="putter_ids = []" class="{{ $chip }}"
                            :class="putter_ids.length === 0 ? 'border-transparent bg-sky-500/20 text-sky-200' : 'border-slate-700 text-slate-500'">Any</button>
                    @foreach ($putters as $putter)
                        <button type="button" @click="toggle(putter_ids, {{ $putter->id }})" class="{{ $chip }}"
                                :class="putter_ids.includes({{ $putter->id }}) ? 'border-transparent bg-sky-500/20 text-sky-200' : 'border-slate-700 text-slate-500'">{{ $putter->name }}</button>
                    @endforeach
                </div>
                <template x-for="id in putter_ids" :key="id"><input type="hidden" name="putter_ids[]" :value="id"></template>
            </div>

            <div>
                <span class="{{ $label }}">Where</span>
                <div class="mt-1 flex flex-wrap gap-1.5">
                    <button type="button" @click="contexts = []; surface_types = []" class="{{ $chip }}"
                            :class="contexts.length === 0 ? 'border-transparent bg-sky-500/20 text-sky-200' : 'border-slate-700 text-slate-500'">Anywhere</button>
                    @foreach (PuttContext::cases() as $context)
                        <button type="button" @click="toggleContext('{{ $context->value }}')" class="{{ $chip }}"
                                :class="contexts.includes('{{ $context->value }}') ? 'border-transparent bg-sky-500/20 text-sky-200' : 'border-slate-700 text-slate-500'">{{ $context->label() }}</button>
                    @endforeach
                </div>
                <template x-for="value in contexts" :key="value"><input type="hidden" name="contexts[]" :value="value"></template>

                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    <template x-for="type in availableSurfaceTypes" :key="type.value">
                        <button type="button" @click="toggle(surface_types, type.value)" class="{{ $chip }}"
                                :class="surface_types.includes(type.value) ? 'border-transparent bg-slate-700 text-slate-100' : 'border-slate-800 text-slate-600'"
                                x-text="type.label"></button>
                    </template>
                </div>
                <template x-for="value in surface_types" :key="value"><input type="hidden" name="surface_types[]" :value="value"></template>
                <p class="mt-1 text-[10px] text-slate-600">Pick surfaces to narrow it further, or none for any.</p>
            </div>

            <div x-show="kind === 'goals'">
                <span class="{{ $label }}">Distance (ft)</span>
                <div class="mt-1 grid grid-cols-2 gap-2">
                    <input type="number" inputmode="numeric" name="min_distance_ft" x-model="min_distance_ft" placeholder="From" min="1" max="120" class="{{ $input }}">
                    <input type="number" inputmode="numeric" name="max_distance_ft" x-model="max_distance_ft" placeholder="To" min="1" max="120" class="{{ $input }}">
                </div>
            </div>
        </section>

        {{-- Drill steps. --}}
        <section x-show="kind === 'drill'" class="space-y-3">
            <h2 class="text-sm font-medium text-slate-300">Steps</h2>

            <div class="rounded-lg bg-slate-900 p-3">
                <div class="{{ $label }}">Build a ladder</div>
                <div class="mt-1 flex items-center gap-1.5 text-xs text-slate-400">
                    <input type="number" inputmode="numeric" x-model.number="ladderFrom" class="w-16 shrink-0 px-2 {{ $field }}" aria-label="From feet">
                    <span>to</span>
                    <input type="number" inputmode="numeric" x-model.number="ladderTo" class="w-16 shrink-0 px-2 {{ $field }}" aria-label="To feet">
                    <span>every</span>
                    <input type="number" inputmode="numeric" x-model.number="ladderEvery" class="w-14 shrink-0 px-2 {{ $field }}" aria-label="Every feet">
                    <span>ft</span>
                </div>
                <button type="button" @click="buildLadder()" class="mt-2 w-full rounded-lg border border-slate-700 py-1.5 text-xs text-slate-300">Replace steps</button>
            </div>

            <div class="space-y-1.5">
                <template x-for="(step, index) in steps" :key="index">
                    <div class="flex items-center gap-1.5">
                        <span class="w-5 text-right text-[11px] text-slate-600" x-text="index + 1"></span>
                        <input type="number" inputmode="numeric" :name="`steps[${index}][distance_ft]`" x-model="step.distance_ft" min="1" max="120" required
                               class="w-16 shrink-0 px-2 {{ $field }}" aria-label="Distance">
                        <span class="text-[11px] text-slate-500">ft</span>
                        <select :name="`steps[${index}][clock_position]`" x-model="step.clock_position" class="min-w-0 flex-1 {{ $field }} !text-sm" aria-label="Position">
                            <option value="">Any spot</option>
                            <option value="flat">Flat</option>
                            <template x-for="position in options.clock" :key="position.value">
                                <option :value="position.value" x-text="position.label" :selected="step.clock_position === position.value"></option>
                            </template>
                        </select>
                        <input type="number" inputmode="numeric" :name="`steps[${index}][makes_required]`" x-model="step.makes_required" min="1" max="20"
                               :placeholder="`×${drill_makes_required || 1}`" class="w-14 shrink-0 px-2 {{ $field }}" aria-label="Sunk needed">
                        <button type="button" @click="steps.splice(index, 1)" class="px-1 text-slate-600" aria-label="Remove step">✕</button>
                    </div>
                </template>
                <button type="button" @click="steps.push({ distance_ft: (steps.at(-1)?.distance_ft ?? 2) * 1 + 1, clock_position: '', makes_required: '' })"
                        class="text-xs text-emerald-400">+ Add step</button>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="{{ $label }}" for="drill_on_miss">On a failed step</label>
                    <select id="drill_on_miss" name="drill_on_miss" x-model="drill_on_miss" class="mt-1 {{ $input }}">
                        @foreach (DrillMissRule::cases() as $rule)
                            <option value="{{ $rule->value }}">{{ $rule->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}" for="drill_order">Order</label>
                    <select id="drill_order" name="drill_order" x-model="drill_order" class="mt-1 {{ $input }}">
                        @foreach (DrillOrder::cases() as $order)
                            <option value="{{ $order->value }}">{{ $order->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}" for="drill_attempts">Attempts per step</label>
                    <input id="drill_attempts" type="number" inputmode="numeric" name="drill_attempts" x-model="drill_attempts" min="1" max="20" class="mt-1 {{ $input }}">
                </div>
                {{-- With one attempt the step is simply sunk or failed; disabled so it posts nothing. --}}
                <div x-show="drill_attempts > 1">
                    <label class="{{ $label }}" for="drill_makes_required">Sunk per step</label>
                    <input id="drill_makes_required" type="number" inputmode="numeric" name="drill_makes_required" x-model="drill_makes_required" min="1" :max="drill_attempts"
                           :disabled="drill_attempts <= 1" class="mt-1 {{ $input }}">
                </div>
                <div>
                    <label class="{{ $label }}" for="drill_rounds">Rounds</label>
                    <input id="drill_rounds" type="number" inputmode="numeric" name="drill_rounds" x-model="drill_rounds" min="1" max="20" class="mt-1 {{ $input }}">
                </div>
            </div>
        </section>

        <section class="space-y-2">
            <h2 class="text-sm font-medium text-slate-300">
                Goals <span x-show="kind === 'drill'" class="text-[11px] font-normal text-slate-500">(optional)</span>
            </h2>

            <template x-for="(goal, index) in goals" :key="index">
                <div class="space-y-2 rounded-lg bg-slate-900 p-3">
                    <div class="flex items-center gap-1.5">
                        <input type="number" inputmode="numeric" :name="`goals[${index}][target]`" x-model="goal.target" min="1" required
                               class="w-24 shrink-0 px-2 {{ $field }}" aria-label="Target">
                        <select :name="`goals[${index}][metric]`" x-model="goal.metric" class="min-w-0 flex-1 {{ $field }}" aria-label="Measure">
                            <template x-for="metric in availableMetrics" :key="metric.value">
                                <option :value="metric.value" x-text="metric.label" :selected="goal.metric === metric.value"></option>
                            </template>
                        </select>
                        <button type="button" @click="goals.splice(index, 1)" class="px-1 text-slate-600" aria-label="Remove goal">✕</button>
                    </div>

                    <div class="grid grid-cols-2 gap-1.5">
                        <select :name="`goals[${index}][period]`" x-model="goal.period" class="{{ $input }} !text-sm" aria-label="Period">
                            <template x-for="period in options.periods" :key="period.value">
                                <option :value="period.value" x-text="period.label" :selected="goal.period === period.value"></option>
                            </template>
                        </select>
                        <select :name="`goals[${index}][context]`" x-model="goal.context" class="{{ $input }} !text-sm" aria-label="Where">
                            <option value="">Anywhere</option>
                            @foreach (PuttContext::cases() as $context)
                                <option value="{{ $context->value }}">{{ $context->label() }} only</option>
                            @endforeach
                        </select>
                        <input type="number" inputmode="numeric" :name="`goals[${index}][min_distance_ft]`" x-model="goal.min_distance_ft" placeholder="From ft" class="{{ $input }} !text-sm" aria-label="From feet">
                        <input type="number" inputmode="numeric" :name="`goals[${index}][max_distance_ft]`" x-model="goal.max_distance_ft" placeholder="To ft" class="{{ $input }} !text-sm" aria-label="To feet">
                        <input type="number" inputmode="numeric" x-show="goal.metric === 'make_percent'" :name="`goals[${index}][min_attempts]`" x-model="goal.min_attempts" placeholder="Min putts" class="{{ $input }} !text-sm" aria-label="Minimum putts">
                        <input type="number" inputmode="numeric" x-show="goal.period !== 'total'" :name="`goals[${index}][periods_required]`" x-model="goal.periods_required"
                               :placeholder="goal.period === 'daily' ? 'Days needed (all)' : 'Weeks needed (all)'" class="{{ $input }} !text-sm" aria-label="Periods needed">
                    </div>
                </div>
            </template>

            <button type="button" @click="goals.push({ metric: 'attempts', period: 'daily', target: 100, context: '', min_distance_ft: '', max_distance_ft: '', min_attempts: '', periods_required: '' })"
                    class="text-xs text-emerald-400">+ Add goal</button>
        </section>

        <div>
            <label for="description" class="{{ $label }}">Notes</label>
            <textarea id="description" name="description" x-model="description" rows="2" placeholder="Optional" class="mt-1 {{ $input }}"></textarea>
        </div>

        <button type="submit" class="w-full rounded-lg bg-emerald-500 py-3 text-sm font-medium text-slate-950">
            {{ $challenge->exists ? 'Save challenge' : 'Create challenge' }}
        </button>
    </form>
@endsection
