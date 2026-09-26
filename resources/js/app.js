import Alpine from 'alpinejs';
import challengeForm from './challenge-form';
import { countsToward, goalDone, goalLine, liveGoal } from './challenges';
import { applyShot, currentStep, makesRequired, startDrill } from './drill';

/**
 * Storage is keyed per player, so two people sharing a phone can never post each
 * other's queued putts. The pre-accounts keys are dropped on load: their putts
 * belonged to a database that has since been reset.
 */
const queueKey = (userId) => `putt-queue:${userId}`;
const prefsKey = (userId) => `putt-prefs:${userId}`;
// Which challenge the logger is focused on lives in this browser only.
const focusKey = (userId) => `putt-focus:${userId}`;
// A drill's place survives a reload, so leaving the page mid-ladder loses nothing.
const drillKey = (userId, challengeId) => `putt-drill:${userId}:${challengeId}`;
const LEGACY_KEYS = ['putt-queue', 'putt-prefs'];

/**
 * How long the dial stays lit up and unclickable after a tap. Long enough to read
 * the confirmation, short enough not to slow down a rapid practice set — and it
 * swallows the double tap that would otherwise log a phantom putt.
 */
const LOCKOUT_MS = 500;

function uuid() {
    if (globalThis.crypto?.randomUUID) {
        return globalThis.crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

function read(key, fallback) {
    try {
        return JSON.parse(localStorage.getItem(key)) ?? fallback;
    } catch {
        return fallback;
    }
}

function write(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage full or unavailable; the putt still posts on the next flush.
    }
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

Alpine.data('puttTracker', (initialProgress, ladder, clock, setup) => ({
    ladder,
    clockRing: clock.ring,
    clockLabels: clock.labels,
    userId: setup.userId,
    putters: setup.putters,
    surfaceTypes: setup.surfaceTypes,
    distance: 10,
    context: 'inside',
    putterId: setup.defaultPutterId,
    surfaceType: null,
    clockPosition: 'flat',
    ringOpen: false,
    location: '',
    advancedOpen: false,
    advancedMisses: false,
    queue: [],
    recent: [],
    synced: initialProgress ?? {},
    syncing: false,
    signedOut: false,
    online: navigator.onLine,
    awake: false,
    wakeLock: null,
    flash: null,
    flashCause: null,
    locked: false,
    focusId: null,
    drillRun: null,
    drillHistory: [],
    celebration: null,

    init() {
        LEGACY_KEYS.forEach((key) => {
            try {
                localStorage.removeItem(key);
            } catch {
                // Storage unavailable; nothing to clean up.
            }
        });

        const prefs = read(prefsKey(this.userId), {});
        this.distance = prefs.distance ?? 10;
        this.context = prefs.context ?? 'inside';
        // A remembered putter may since have been retired or deleted.
        this.putterId = this.putters.some((p) => p.id === prefs.putterId) ? prefs.putterId : setup.defaultPutterId;
        this.surfaceType = this.surfaceOptions.some((t) => t.value === prefs.surfaceType) ? prefs.surfaceType : null;
        this.clockPosition = prefs.clockPosition ?? 'flat';
        this.advancedMisses = prefs.advancedMisses ?? false;
        this.location = prefs.location ?? '';
        this.queue = read(queueKey(this.userId), []);
        this.focusId = read(focusKey(this.userId), {}).id ?? null;
        this.applyFocus();

        window.addEventListener('online', () => {
            this.online = true;
            this.flush();
        });
        window.addEventListener('offline', () => {
            this.online = false;
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                // iOS drops the wake lock whenever the page is backgrounded.
                if (this.awake) {
                    this.acquireWakeLock();
                }
                this.flush();
            }
        });

        this.flush();
        setInterval(() => this.flush(), 30000);
    },

    /**
     * Every putt logged since the page loaded. It is deliberately not truncated:
     * it backs both the undo stack and the session tally, so a cap would silently
     * stop the counter climbing. The list is display-sliced in the view instead.
     *
     * The on-screen summary covers the putter in hand only, so switching
     * mid-practice does not blend the two. `recent` itself stays unfiltered so undo
     * still reaches back across a switch.
     */
    get sessionPutts() {
        return this.recent.filter((p) => p.putter_id === this.putterId);
    },

    get putterName() {
        return this.putters.find((p) => p.id === this.putterId)?.name ?? '';
    },

    get surfaceOptions() {
        return this.surfaceTypes[this.context] ?? [];
    },

    get challenges() {
        return this.synced.challenges ?? [];
    },

    /**
     * The focused challenge, or null once it has ended or been deleted — the
     * stored id is simply ignored until the player picks another.
     */
    get focused() {
        return this.challenges.find((challenge) => challenge.id === this.focusId) ?? null;
    },

    get focusedGoals() {
        return (this.focused?.goals ?? []).map((goal) => {
            const live = liveGoal(this.focused, goal, this.queue);

            return { ...live, done: goalDone(live), line: goalLine(live) };
        });
    },

    /**
     * The putt the next tap would log, in the shape the eligibility rules read.
     */
    get nextPutt() {
        return {
            putter_id: this.putterId,
            context: this.context,
            surface_type: this.surfaceType,
            distance_ft: this.distance,
        };
    },

    countsFor(challenge) {
        return countsToward(challenge, this.nextPutt);
    },

    get putterLocked() {
        return (this.focused?.eligibility.putter_ids.length ?? 0) === 1;
    },

    get contextLocked() {
        return (this.focused?.eligibility.contexts.length ?? 0) === 1;
    },

    get surfaceLocked() {
        return (this.focused?.eligibility.surface_types.length ?? 0) === 1;
    },

    get distanceLocked() {
        const rules = this.focused?.eligibility;

        return Boolean(this.focused?.drill)
            || (rules && rules.min_distance_ft !== null && rules.min_distance_ft === rules.max_distance_ft);
    },

    focus(id) {
        this.focusId = id;
        write(focusKey(this.userId), { id });
        this.applyFocus();
    },

    unfocus() {
        this.focusId = null;
        this.drillRun = null;
        this.drillHistory = [];
        write(focusKey(this.userId), {});
    },

    /**
     * When the focused challenge allows exactly one putter, place or surface,
     * the logger shows that one and stops offering the others. A drill goes
     * further and sets the distance and spot for every putt.
     */
    applyFocus() {
        const challenge = this.focused;

        if (!challenge) {
            this.drillRun = null;

            return;
        }

        const rules = challenge.eligibility;

        if (rules.putter_ids.length === 1 && this.putters.some((p) => p.id === rules.putter_ids[0])) {
            this.putterId = rules.putter_ids[0];
        }

        if (rules.contexts.length === 1 && this.context !== rules.contexts[0]) {
            this.surfaceType = null;
            this.context = rules.contexts[0];
        }

        if (rules.surface_types.length === 1) {
            this.surfaceType = rules.surface_types[0];
        }

        if (rules.min_distance_ft !== null && rules.min_distance_ft === rules.max_distance_ft) {
            this.distance = rules.min_distance_ft;
        }

        if (challenge.drill) {
            this.drillRun = read(drillKey(this.userId, challenge.id), null) ?? this.newDrillRun();
            this.syncDrillPosition();
        } else {
            this.drillRun = null;
        }

        this.savePrefs();
    },

    newDrillRun() {
        return { uuid: uuid(), state: startDrill(this.focused.drill) };
    },

    saveDrill() {
        if (this.focused && this.drillRun) {
            write(drillKey(this.userId, this.focused.id), this.drillRun);
        }
    },

    restartDrill() {
        this.drillRun = this.newDrillRun();
        this.drillHistory = [];
        this.saveDrill();
        this.syncDrillPosition();
    },

    get drillStep() {
        if (!this.focused?.drill || !this.drillRun) {
            return null;
        }

        const drill = this.focused.drill;
        const index = currentStep(drill, this.drillRun.state);
        const step = drill.steps[index];

        if (!step) {
            return null;
        }

        return {
            index,
            number: this.drillRun.state.cleared.length + 1,
            total: drill.steps.length,
            round: this.drillRun.state.round + 1,
            rounds: drill.rounds,
            distance: step.distance_ft,
            clock: step.clock_position ? this.clockLabels[step.clock_position]?.clock : null,
            needed: makesRequired(drill, index) - this.drillRun.state.streak,
            attempts: this.drillRun.state.attempts,
        };
    },

    /**
     * Put the ball where the drill says: its distance always, and its spot on the
     * clock when the step names one.
     */
    syncDrillPosition() {
        const step = this.drillStep;

        if (!step) {
            return;
        }

        this.distance = step.distance;

        const position = this.focused.drill.steps[step.index].clock_position;

        if (position) {
            this.clockPosition = position;
        }
    },

    get sessionCount() {
        return this.sessionPutts.length;
    },

    get sessionSunk() {
        return this.sessionPutts.filter((p) => p.result === 'sunk').length;
    },

    get sessionPercent() {
        return this.sessionCount ? Math.round((this.sessionSunk / this.sessionCount) * 100) : 0;
    },

    get pending() {
        return this.queue.length;
    },

    savePrefs() {
        write(prefsKey(this.userId), {
            distance: this.distance,
            context: this.context,
            putterId: this.putterId,
            surfaceType: this.surfaceType,
            clockPosition: this.clockPosition,
            advancedMisses: this.advancedMisses,
            location: this.location,
        });
    },

    setContext(context) {
        if (this.contextLocked) {
            return;
        }

        // "Flat" is a claim about the surface, and it is only reliably true of the
        // indoor mat. Stepping outside retires the claim rather than carrying it
        // silently onto a green that has a fall line — the bar then prompts for a
        // real position instead of quietly mislabelling every putt.
        if (context === 'outside' && this.clockPosition === 'flat') {
            this.clockPosition = null;
        } else if (context === 'inside' && this.clockPosition === null) {
            this.clockPosition = 'flat';
        }

        // Every surface type belongs to one context, so crossing over clears it.
        if (context !== this.context) {
            this.surfaceType = null;
        }

        this.context = context;
        this.savePrefs();
    },

    setSurfaceType(type) {
        if (this.surfaceLocked) {
            return;
        }

        this.surfaceType = this.surfaceType === type ? null : type;
        this.savePrefs();
    },

    get clockLabel() {
        return this.clockLabels[this.clockPosition]?.clock ?? '';
    },

    get clockDetail() {
        return this.clockLabels[this.clockPosition]?.detail ?? '';
    },

    setClockPosition(position) {
        this.clockPosition = position;
        this.ringOpen = false;
        this.savePrefs();
    },

    /**
     * Walk round the hole. Stepping from flat or from nothing enters the ring at
     * twelve rather than refusing to move.
     */
    stepClockPosition(direction) {
        const index = this.clockRing.indexOf(this.clockPosition);

        this.clockPosition = index === -1
            ? this.clockRing[0]
            : this.clockRing[(index + direction + this.clockRing.length) % this.clockRing.length];

        this.savePrefs();
    },

    setPutter(putterId) {
        if (this.putterLocked) {
            return;
        }

        this.putterId = putterId;
        this.savePrefs();
    },

    toggleAdvancedMisses() {
        this.advancedMisses = !this.advancedMisses;
        this.savePrefs();
    },

    stepDistance(direction) {
        if (this.distanceLocked) {
            return;
        }

        const index = this.ladder.indexOf(this.distance);

        if (index === -1) {
            const nearest = this.ladder.reduce((a, b) =>
                Math.abs(b - this.distance) < Math.abs(a - this.distance) ? b : a,
            );
            this.distance = nearest;
        } else {
            const next = Math.min(Math.max(index + direction, 0), this.ladder.length - 1);
            this.distance = this.ladder[next];
        }

        this.savePrefs();
    },

    setDistance(value) {
        if (this.distanceLocked) {
            return;
        }

        const parsed = parseInt(value, 10);

        if (!Number.isNaN(parsed) && parsed >= 1 && parsed <= 120) {
            this.distance = parsed;
            this.savePrefs();
        }
    },

    /**
     * `cause` only comes from the split left/right wedges in advanced mode, and the
     * server drops it on any other result.
     */
    record(result, cause = null) {
        // A tap landing inside the lockout is a fat finger, not a second putt.
        if (this.locked) {
            return;
        }

        this.locked = true;

        const putt = {
            uuid: uuid(),
            distance_ft: this.distance,
            result,
            miss_cause: cause,
            context: this.context,
            surface_type: this.surfaceType,
            putter_id: this.putterId,
            clock_position: this.clockPosition || null,
            location: this.location || null,
            hit_at: new Date().toISOString(),
        };

        const step = this.drillStep;

        if (step) {
            Object.assign(putt, {
                distance_ft: step.distance,
                challenge_id: this.focused.id,
                challenge_run_uuid: this.drillRun.uuid,
                drill_step: step.index,
            });
            this.advanceDrill(putt, result === 'sunk');
        }

        this.queue.push(putt);
        this.recent.unshift(putt);
        write(queueKey(this.userId), this.queue);

        this.flash = result;
        this.flashCause = cause;
        setTimeout(() => {
            this.flash = null;
            this.flashCause = null;
            this.locked = false;
        }, LOCKOUT_MS);

        this.flush();
    },

    /**
     * Move the drill on after a shot, keeping the previous place so undo can put
     * the player back exactly where they were.
     */
    advanceDrill(putt, made) {
        this.drillHistory.push({ uuid: putt.uuid, run: this.drillRun });

        const state = applyShot(this.focused.drill, this.drillRun.state, made);

        if (state.completed) {
            this.celebration = `${this.focused.name} finished in ${state.attempts} putts!`;
            setTimeout(() => (this.celebration = null), 6000);
            this.drillRun = this.newDrillRun();
        } else {
            this.drillRun = { ...this.drillRun, state };
        }

        this.saveDrill();
        this.syncDrillPosition();
    },

    async undo() {
        const last = this.recent.shift();

        if (!last) {
            return;
        }

        if (this.drillHistory.at(-1)?.uuid === last.uuid) {
            this.drillRun = this.drillHistory.pop().run;
            this.saveDrill();
            this.syncDrillPosition();
        }

        const queued = this.queue.findIndex((p) => p.uuid === last.uuid);

        if (queued !== -1) {
            this.queue.splice(queued, 1);
            write(queueKey(this.userId), this.queue);
        } else {
            const sunk = last.result === 'sunk' ? 1 : 0;

            ['today', 'week'].forEach((period) => {
                const tally = this.synced[period];

                if (tally) {
                    tally.total = Math.max(0, tally.total - 1);
                    tally.sunk = Math.max(0, tally.sunk - sunk);
                }
            });

            try {
                await fetch(`/api/putts/${last.uuid}`, {
                    method: 'DELETE',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                });
            } catch {
                // Rolled back locally; the row is removed on the next connection.
            }
        }
    },

    /**
     * The server snapshot plus anything still sitting in the local queue, so the
     * counter never jumps backwards when a batch syncs. Queued putts are recent by
     * nature, so they are simply added to both periods.
     */
    get progress() {
        const base = this.synced ?? {};
        const queued = this.queue.length;
        const queuedSunk = this.queue.filter((p) => p.result === 'sunk').length;
        const add = (tally) => ({
            total: (tally?.total ?? 0) + queued,
            sunk: (tally?.sunk ?? 0) + queuedSunk,
        });

        return {
            ...base,
            today: add(base.today),
            week: add(base.week),
        };
    },

    async flush() {
        if (this.syncing || this.queue.length === 0 || !navigator.onLine) {
            return;
        }

        this.syncing = true;
        const batch = this.queue.slice(0, 100);
        let drained = false;

        try {
            const response = await fetch('/api/putts/sync', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ putts: batch }),
            });

            // Logged out, or the session behind the CSRF token expired. The queue is
            // kept exactly as it is and posts again once the player logs back in.
            this.signedOut = response.status === 401 || response.status === 419;

            if (response.ok) {
                const data = await response.json();
                const stored = new Set(data.stored ?? []);
                this.queue = this.queue.filter((p) => !stored.has(p.uuid));
                write(queueKey(this.userId), this.queue);

                if (data.progress) {
                    const hadFocus = this.focused !== null;
                    this.synced = data.progress;

                    // The first sync of a page opened offline brings the challenges in.
                    if (!hadFocus && this.focused) {
                        this.applyFocus();
                    }
                }

                drained = true;
            }
        } catch {
            // Offline or server unreachable; the queue survives for the next attempt.
        } finally {
            this.syncing = false;
        }

        // Only chain another batch after a success, otherwise a failing request
        // would retry in a tight loop. A failed queue waits for the next trigger.
        if (drained && this.queue.length > 0 && navigator.onLine) {
            this.flush();
        }
    },

    async toggleWakeLock() {
        if (this.awake) {
            this.awake = false;
            try {
                await this.wakeLock?.release();
            } catch {
                // Already released.
            }
            this.wakeLock = null;

            return;
        }

        this.awake = true;
        await this.acquireWakeLock();
    },

    async acquireWakeLock() {
        if (!('wakeLock' in navigator)) {
            this.awake = false;

            return;
        }

        try {
            this.wakeLock = await navigator.wakeLock.request('screen');
            this.wakeLock.addEventListener('release', () => {
                this.wakeLock = null;
            });
        } catch {
            this.awake = false;
        }
    },

    get wakeLockSupported() {
        return 'wakeLock' in navigator;
    },
}));

Alpine.data('challengeForm', challengeForm);

/**
 * Point the logger at a challenge and go there. Focus is remembered in this
 * browser only, keyed per player like everything else the logger stores.
 */
Alpine.data('focusChallenge', (userId, challengeId, logUrl) => ({
    go() {
        write(focusKey(userId), { id: challengeId });
        window.location = logUrl;
    },
}));

window.Alpine = Alpine;
Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
