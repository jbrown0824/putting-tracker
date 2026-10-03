/**
 * The challenge editor: repeaters for goals and drill steps, a ladder builder,
 * and presets that fill the whole form in one tap.
 */
const addDays = (isoDate, days) => {
    const date = new Date(`${isoDate}T12:00:00`);
    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
};

const range = (from, to, every) => {
    const steps = [];

    for (let feet = from; feet <= to && steps.length < 50; feet += Math.max(1, every)) {
        steps.push(feet);
    }

    return steps;
};

const goal = (overrides) => ({
    metric: 'attempts',
    period: 'total',
    target: 100,
    context: '',
    min_distance_ft: '',
    max_distance_ft: '',
    min_attempts: '',
    periods_required: '',
    ...overrides,
});

const step = (distance, clockPosition = '') => ({ distance_ft: distance, clock_position: clockPosition, makes_required: '' });

export default (initial, options) => ({
    ...initial,
    goals: (initial.goals ?? []).map((g) => goal(g)),
    steps: (initial.steps ?? []).map((s) => ({ ...step(s.distance_ft, s.clock_position ?? ''), makes_required: s.makes_required ?? '' })),
    options,
    ladderFrom: 3,
    ladderTo: 10,
    ladderEvery: 1,

    presets: [
        { key: 'ladder', label: 'Ladder 3–10 ft' },
        { key: 'clock', label: 'Around the clock' },
        { key: 'daily', label: '100 putts a day' },
        { key: 'volume', label: '2,000 in 4 weeks' },
        { key: 'streak', label: '25 in a row' },
        { key: 'lag', label: 'Long putts on the mat' },
    ],

    get availableSurfaceTypes() {
        return this.contexts.length === 0
            ? this.options.surfaceTypes
            : this.options.surfaceTypes.filter((type) => this.contexts.includes(type.context));
    },

    /**
     * "Drills completed" only means something on a drill.
     */
    get availableMetrics() {
        return this.kind === 'drill'
            ? this.options.metrics
            : this.options.metrics.filter((metric) => metric.value !== 'drill_runs_completed');
    },

    toggle(list, value) {
        const index = list.indexOf(value);

        if (index === -1) {
            list.push(value);
        } else {
            list.splice(index, 1);
        }
    },

    toggleContext(context) {
        this.toggle(this.contexts, context);

        // A surface type belongs to one context, so dropping the context drops it too.
        const allowed = this.availableSurfaceTypes.map((type) => type.value);
        this.surface_types = this.surface_types.filter((type) => allowed.includes(type));
    },

    buildLadder() {
        this.steps = range(Number(this.ladderFrom), Number(this.ladderTo), Number(this.ladderEvery)).map((feet) => step(feet));
    },

    applyPreset(key) {
        const today = this.options.today;
        const base = {
            description: '',
            starts_on: today,
            ends_on: '',
            putter_ids: [],
            contexts: [],
            surface_types: [],
            min_distance_ft: '',
            max_distance_ft: '',
            goals: [],
            steps: [],
            drill_on_miss: 'restart',
            drill_order: 'sequential',
            drill_makes_required: 1,
            drill_attempts: 1,
            drill_rounds: 1,
        };

        const presets = {
            ladder: {
                name: 'Ladder 3–10 ft',
                kind: 'drill',
                steps: range(3, 10, 1).map((feet) => step(feet)),
                goals: [goal({ metric: 'drill_runs_completed', period: 'weekly', target: 3 })],
            },
            clock: {
                name: 'Around the clock at 4 ft',
                kind: 'drill',
                contexts: ['outside'],
                steps: this.options.clock.map((position) => step(4, position.value)),
            },
            daily: {
                name: '100 putts a day',
                kind: 'goals',
                ends_on: addDays(today, 29),
                goals: [goal({ period: 'daily', target: 100 })],
            },
            volume: {
                name: '2,000 putts in 4 weeks',
                kind: 'goals',
                ends_on: addDays(today, 27),
                goals: [goal({ target: 2000 }), goal({ target: 400, context: 'outside' })],
            },
            streak: {
                name: '25 in a row from 4 ft',
                kind: 'goals',
                min_distance_ft: 4,
                max_distance_ft: 4,
                goals: [goal({ metric: 'make_streak', target: 25 })],
            },
            lag: {
                name: 'Long putts on the mat',
                kind: 'goals',
                ends_on: addDays(today, 29),
                contexts: ['inside'],
                surface_types: ['mat'],
                min_distance_ft: 20,
                goals: [goal({ period: 'weekly', target: 200 }), goal({ metric: 'make_percent', target: 15, min_attempts: 100 })],
            },
        };

        Object.assign(this, base, presets[key]);
    },
});
