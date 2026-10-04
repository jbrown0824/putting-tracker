/**
 * The guided-drill rules, run on the phone so it can tell the player where to
 * stand next with no connection.
 *
 * This mirrors app/Services/DrillEngine.php, which replays the same putts on the
 * server to decide whether a run was finished. Change the rules in both places.
 */
export function startDrill(drill) {
    return pickPending(drill, {
        round: 0,
        step: 0,
        stepPutts: 0,
        stepSunk: 0,
        cleared: [],
        attempts: 0,
        completed: false,
        pending: null,
    });
}

export function sunkRequired(drill, index) {
    return Math.max(1, Number(drill.steps[index]?.makes_required ?? drill.makes_required ?? 1));
}

/**
 * Putts allowed at a step: never fewer than it needs sunk.
 */
export function attemptsAllowed(drill, index) {
    return Math.max(sunkRequired(drill, index), Number(drill.attempts ?? 1));
}

/**
 * The step to putt from now.
 */
export function currentStep(drill, state) {
    return drill.order === 'random' ? state.pending : state.step;
}

/**
 * A random drill chooses its next step from whatever is still uncleared this round.
 */
function pickPending(drill, state) {
    if (drill.order !== 'random') {
        return state;
    }

    if (state.pending !== null && !state.cleared.includes(state.pending)) {
        return state;
    }

    const open = drill.steps.map((_, index) => index).filter((index) => !state.cleared.includes(index));

    return { ...state, pending: open.length ? open[Math.floor(Math.random() * open.length)] : null };
}

export function applyShot(drill, previous, made) {
    const count = drill.steps.length;
    const state = { ...previous, cleared: [...previous.cleared] };
    const step = currentStep(drill, state);

    if (state.completed || count === 0 || step === null) {
        return state;
    }

    // A run saved before steps had attempts kept a streak of makes instead.
    state.attempts++;
    state.stepPutts = (state.stepPutts ?? state.streak ?? 0) + 1;
    state.stepSunk = (state.stepSunk ?? state.streak ?? 0) + (made ? 1 : 0);
    delete state.streak;

    const required = sunkRequired(drill, step);

    if (state.stepSunk >= required) {
        state.stepPutts = 0;
        state.stepSunk = 0;
        state.cleared.push(step);
        state.step = step + 1;

        if (state.cleared.length < count) {
            return pickPending(drill, state);
        }

        state.round++;
        state.cleared = [];
        state.step = 0;
        state.completed = state.round >= Math.max(1, Number(drill.rounds ?? 1));

        return pickPending(drill, { ...state, pending: null });
    }

    // Still enough putts left at this step to sink the rest.
    if (state.stepPutts - state.stepSunk <= attemptsAllowed(drill, step) - required) {
        return state;
    }

    state.stepPutts = 0;
    state.stepSunk = 0;

    if ((drill.on_miss ?? 'restart') === 'restart') {
        state.cleared = [];
        state.step = 0;
    } else if (drill.on_miss === 'step_back' && state.cleared.length > 0) {
        const reopened = state.cleared.pop();

        if (drill.order !== 'random') {
            state.step = reopened;
        }
    }

    return pickPending(drill, state);
}
