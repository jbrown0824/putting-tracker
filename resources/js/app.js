import Alpine from 'alpinejs';

const QUEUE_KEY = 'putt-queue';
const PREFS_KEY = 'putt-prefs';

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

Alpine.data('puttTracker', (initialProgress, ladder) => ({
    ladder,
    distance: 10,
    context: 'inside',
    putter: 'blade',
    slope: null,
    breakDirection: null,
    location: '',
    surface: '',
    advancedOpen: false,
    queue: [],
    recent: [],
    synced: initialProgress ?? {},
    syncing: false,
    online: navigator.onLine,
    awake: false,
    wakeLock: null,
    flash: null,

    init() {
        const prefs = read(PREFS_KEY, {});
        this.distance = prefs.distance ?? 10;
        this.context = prefs.context ?? 'inside';
        this.putter = prefs.putter ?? 'blade';
        this.slope = prefs.slope ?? null;
        this.breakDirection = prefs.breakDirection ?? null;
        this.location = prefs.location ?? '';
        this.surface = prefs.surface ?? '';
        this.queue = read(QUEUE_KEY, []);

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
     * The on-screen session summary covers the putter in hand only, so switching
     * mid-practice does not blend the two. `recent` itself stays unfiltered so undo
     * still reaches back across a switch.
     */
    get sessionPutts() {
        return this.recent.filter((p) => p.putter === this.putter);
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
        write(PREFS_KEY, {
            distance: this.distance,
            context: this.context,
            putter: this.putter,
            slope: this.slope,
            breakDirection: this.breakDirection,
            location: this.location,
            surface: this.surface,
        });
    },

    setContext(context) {
        this.context = context;
        this.savePrefs();
    },

    setPutter(putter) {
        this.putter = putter;
        this.savePrefs();
    },

    stepDistance(direction) {
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
        const parsed = parseInt(value, 10);

        if (!Number.isNaN(parsed) && parsed >= 1 && parsed <= 120) {
            this.distance = parsed;
            this.savePrefs();
        }
    },

    record(result) {
        const putt = {
            uuid: uuid(),
            distance_ft: this.distance,
            result,
            context: this.context,
            putter: this.putter,
            slope: this.slope || null,
            break_direction: this.breakDirection || null,
            location: this.location || null,
            surface: this.surface || null,
            hit_at: new Date().toISOString(),
        };

        this.queue.push(putt);
        this.recent.unshift(putt);
        this.recent = this.recent.slice(0, 12);
        write(QUEUE_KEY, this.queue);

        this.flash = result;
        setTimeout(() => {
            this.flash = null;
        }, 180);

        this.flush();
    },

    async undo() {
        const last = this.recent.shift();

        if (!last) {
            return;
        }

        const queued = this.queue.findIndex((p) => p.uuid === last.uuid);

        if (queued !== -1) {
            this.queue.splice(queued, 1);
            write(QUEUE_KEY, this.queue);
        } else {
            this.synced.total = Math.max(0, (this.synced.total ?? 0) - 1);
            this.synced.sunk = Math.max(0, (this.synced.sunk ?? 0) - (last.result === 'sunk' ? 1 : 0));

            if (last.context === 'outside') {
                this.synced.outside = Math.max(0, (this.synced.outside ?? 0) - 1);
            }

            try {
                await fetch(`/api/putts/${last.uuid}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrf() },
                });
            } catch {
                // Rolled back locally; the row is removed on the next connection.
            }
        }
    },

    /**
     * The server snapshot plus anything still sitting in the local queue, so the
     * counter never jumps backwards when a batch syncs.
     */
    get progress() {
        const base = this.synced ?? {};
        const queued = this.queue.length;
        const queuedOutside = this.queue.filter((p) => p.context === 'outside').length;
        const queuedSunk = this.queue.filter((p) => p.result === 'sunk').length;

        const total = (base.total ?? 0) + queued;
        const outside = (base.outside ?? 0) + queuedOutside;
        const targetTotal = base.target_total ?? 0;
        const targetOutside = base.target_outside_min ?? 0;
        const days = base.days_remaining ?? 0;
        const remaining = Math.max(0, targetTotal - total);
        const outsideRemaining = Math.max(0, targetOutside - outside);

        return {
            ...base,
            total,
            outside,
            sunk: (base.sunk ?? 0) + queuedSunk,
            remaining,
            outside_remaining: outsideRemaining,
            per_day_needed: days > 0 ? Math.ceil(remaining / days) : remaining,
            outside_per_day_needed: days > 0 ? Math.ceil(outsideRemaining / days) : outsideRemaining,
            percent_complete: targetTotal ? Math.round((total / targetTotal) * 1000) / 10 : 0,
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

            if (response.ok) {
                const data = await response.json();
                const stored = new Set(data.stored ?? []);
                this.queue = this.queue.filter((p) => !stored.has(p.uuid));
                write(QUEUE_KEY, this.queue);

                if (data.progress) {
                    this.synced = data.progress;
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

window.Alpine = Alpine;
Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
