---
paths:
  - 'app/Services/**'
---

# Services

## Challenge progress is putter-agnostic; performance stats are not
PuttStats::forPutter() scopes missDial, speedVsLine, byDistance and insights (and anything derived from them) to one putter via baseQuery().

progress() and dailyVolume() deliberately keep using Putt::query() and stay combined, even on a scoped instance. The challenge is a volume goal — every putt counts towards 2,000 no matter which putter hit it. Splitting it would mean neither putter ever reaches the target.

If you add a method to PuttStats, decide which side it belongs on and use baseQuery() unless it feeds the challenge.
