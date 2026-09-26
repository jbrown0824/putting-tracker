---
paths:
  - 'app/Enums/**'
---

# Enums

## Twelve o'clock is the high side, and ClockPosition supersedes slope and break
ClockPosition records where the ball sat on a clock centred on the hole, with 12 ALWAYS the high side of the slope — not a compass direction, not the way the player faces. Every reading of the data depends on that convention holding.

It replaces the old slope and break_direction inputs. slope() and breakSide() are derived from it, never stored twice. RecordPutts writes slope from the position so the column stays consistent.

The break_direction column is gone: the schema was rebuilt from scratch when accounts arrived (Sept 2026), with the owner's agreement that the old data was disposable. ClockPosition::fromLegacy() stays because a phone running a very old cached bundle can still post slope + break_direction, and RecordPutts converts them.

Never assume a column is dead because it is empty locally. The dev database is demo data; production is not.

Flat is a real recorded value, distinct from null. Null means never captured (every putt before Aug 2026); Flat means captured and there is no fall line. Never collapse them — it would make indoor putts indistinguishable from untagged ones forever.

## Putters are rows, not an enum
There is no Putter enum any more. Putters belong to a user (App\Models\Putter); PutterHeadType is descriptive only and must never be used to identify a putter — a player can own two mallets. SurfaceType refines PuttContext and each type belongs to exactly one context; a surface that contradicts the context is dropped, never trusted over it.
