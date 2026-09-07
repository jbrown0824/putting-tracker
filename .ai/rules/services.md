---
paths:
  - 'app/Services/**'
---

# Services

## Challenge progress is putter-agnostic; performance stats are not
PuttStats::forPutter() scopes missDial, speedVsLine, byDistance and insights (and anything derived from them) to one putter via baseQuery().

progress() and dailyVolume() deliberately keep using Putt::query() and stay combined, even on a scoped instance. The challenge is a volume goal — every putt counts towards 2,000 no matter which putter hit it. Splitting it would mean neither putter ever reaches the target.

If you add a method to PuttStats, decide which side it belongs on and use baseQuery() unless it feeds the challenge.

## Compare make rates through AdjustedRate, never raw
A raw make rate is confounded by shot mix: hitting more short putts with one putter raises its number without the stroke improving. Any comparison between two scopes (putter vs putter, week vs week, inside vs outside) must go through AdjustedRate, which does direct standardisation over distance band x context x slope band.

Dimensions engage progressively. Distance and context are always on because every putt has them. Slope only joins once every scope has MIN_SLOPE_CLASSIFIED tagged putts, because a mostly-unknown dimension splits cells without adding signal. Never force it on.

When standardising one scope, the reference must share the page's context filter. Standardising an outside-only scope against an all-putts reference shares almost no strata, so coverage never clears MIN_COVERAGE and the figure silently disappears.

Insights that compare slopes or break sides must exclude Flat and Straight. Both pool the indoor mat, so leaving them in compares carpet against real greens and reports it as a slope effect.
