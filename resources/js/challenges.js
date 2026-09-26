/**
 * Whether a putt counts towards a challenge, and how far its goals have got —
 * the phone's copy of Challenge::eligiblePutts() and ChallengeGoal::covers(), used
 * to show progress before the queue has synced. The server's figures replace
 * these on every sync.
 */
export function countsToward(challenge, putt) {
    const rules = challenge.eligibility;

    return (rules.putter_ids.length === 0 || rules.putter_ids.includes(putt.putter_id))
        && (rules.contexts.length === 0 || rules.contexts.includes(putt.context))
        && (rules.surface_types.length === 0 || rules.surface_types.includes(putt.surface_type))
        && (rules.min_distance_ft === null || putt.distance_ft >= rules.min_distance_ft)
        && (rules.max_distance_ft === null || putt.distance_ft <= rules.max_distance_ft);
}

export function goalCovers(goal, putt) {
    return (goal.context === null || goal.context === putt.context)
        && (goal.min_distance_ft === null || putt.distance_ft >= goal.min_distance_ft)
        && (goal.max_distance_ft === null || putt.distance_ft <= goal.max_distance_ft);
}

const COUNTABLE = ['attempts', 'makes'];

/**
 * The server's figure for a goal plus whatever matching putts are still queued.
 * Only plain counts can be topped up this way; a rate or a streak waits for the sync.
 */
export function liveGoal(challenge, goal, queue) {
    if (!COUNTABLE.includes(goal.metric)) {
        return { ...goal, pending: 0 };
    }

    const today = new Date().toDateString();
    const pending = queue.filter((putt) => countsToward(challenge, putt)
        && goalCovers(goal, putt)
        && (goal.metric === 'attempts' || putt.result === 'sunk')
        && (goal.period !== 'daily' || new Date(putt.hit_at).toDateString() === today)).length;

    if (goal.current) {
        const value = goal.current.value + pending;

        return {
            ...goal,
            pending,
            current: { ...goal.current, value, remaining: Math.max(0, goal.target - value), met: value >= goal.target },
        };
    }

    const value = goal.value + pending;

    return { ...goal, pending, value, remaining: Math.max(0, goal.target - value) };
}

/**
 * Whether a goal is met right now. A rate or a streak also has a minimum sample
 * the phone cannot see, so for those the server's verdict stands.
 */
export function goalDone(goal) {
    if (goal.current) {
        return goal.current.met;
    }

    return COUNTABLE.includes(goal.metric) ? goal.value >= goal.target : goal.status === 'complete';
}

/**
 * The one line the focus bar shows for a goal.
 */
export function goalLine(goal) {
    const unit = goal.metric === 'make_percent' ? '%' : '';
    const fmt = (n) => `${Number(n).toLocaleString()}${unit}`;

    if (goal.current) {
        const status = goal.current.met ? 'done ✓' : `${fmt(goal.current.remaining)} to go`;
        const days = goal.period === 'weekly' && !goal.current.met ? ` · ${goal.current.days_left}d left` : '';

        return `${goal.current.label} ${fmt(goal.current.value)} / ${fmt(goal.target)} · ${status}${days}`;
    }

    if (goalDone(goal)) {
        return `${fmt(goal.value)} / ${fmt(goal.target)} · done ✓`;
    }

    const pace = goal.per_day_needed ? ` · ${goal.per_day_needed.toLocaleString()}/day` : '';

    return `${fmt(goal.value)} / ${fmt(goal.target)}${pace}`;
}
