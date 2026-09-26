---
paths:
  - 'app/Services/**'
---

# Services

## Challenge progress is read from the putts, never stamped onto them
ChallengeProgress decides which putts count by querying Challenge::eligiblePutts() (window, putters, contexts, surface types, distance) and then ChallengeGoal::covers(). Nothing links a putt to a challenge. That is what lets challenges stack — one putt counts toward every challenge it fits — and lets an edited filter recount history. Do not add a challenge_putt table or cache per-putt eligibility.

Challenge progress is deliberately separate from PuttStats. PuttStats scopes performance (putter, context, session, position) and must be user-scoped with forUser() or it throws; ChallengeProgress applies the challenge's own filters instead.

Days are the player's calendar days (users.timezone), grouped in PHP. Compare stored challenge dates as date strings (Challenge::hasStarted/hasEnded), never as Carbon instants — a user-timezone "today" against a UTC-midnight date shifts a challenge by a day either side of UTC.

## DrillEngine and resources/js/drill.js are one rule set in two languages
The phone runs drill.js to guide each putt offline; SettleDrillRuns replays the run's putts through DrillEngine to decide whether it finished. A rule changed on one side only means the phone congratulates runs the server will not count. Change both, and extend DrillEngineTest.

## Compare make rates through AdjustedRate, never raw
A raw make rate is confounded by shot mix: hitting more short putts with one putter raises its number without the stroke improving. Any comparison between two scopes (putter vs putter, week vs week, inside vs outside) must go through AdjustedRate, which does direct standardisation over distance band x context x slope band.

Dimensions engage progressively. Distance and context are always on because every putt has them. Slope only joins once every scope has MIN_SLOPE_CLASSIFIED tagged putts, because a mostly-unknown dimension splits cells without adding signal. Never force it on.

When standardising one scope, the reference must share the page's context filter. Standardising an outside-only scope against an all-putts reference shares almost no strata, so coverage never clears MIN_COVERAGE and the figure silently disappears.

Insights that compare slopes or break sides must exclude Flat and Straight. Both pool the indoor mat, so leaving them in compares carpet against real greens and reports it as a slope effect.
