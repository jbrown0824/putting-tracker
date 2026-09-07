---
paths:
  - 'app/Enums/**'
---

# Enums

## Twelve o'clock is the high side, and ClockPosition supersedes slope and break
ClockPosition records where the ball sat on a clock centred on the hole, with 12 ALWAYS the high side of the slope — not a compass direction, not the way the player faces. Every reading of the data depends on that convention holding.

It replaces the old slope and break_direction inputs. slope() and breakSide() are derived from it, never stored twice. RecordPutts writes slope from the position so the column stays consistent.

break_direction is NOT dropped, and the reason is a trap worth remembering: it had zero rows locally and looked dead, but production held 58. Paired with slope it pins down exactly one position — ClockPosition::fromLegacy() does that conversion and there is a round-trip test proving the bijection. Dropping it alongside adding clock_position would have destroyed the only copy before anything read it. Expand now, contract later: back the data out with putts:backfill-positions, verify, and only then drop the column.

Never assume a column is dead because it is empty locally. The dev database is demo data; production is not.

Flat is a real recorded value, distinct from null. Null means never captured (every putt before Aug 2026); Flat means captured and there is no fall line. Never collapse them — it would make indoor putts indistinguishable from untagged ones forever.
