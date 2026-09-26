---
paths:
  - 'app/Http/Requests/**'
---

# Requests

## Keep putts.*.putter_id nullable, and unchecked, on the sync endpoint
StorePuttsRequest validates putts.*.putter_id as a nullable integer only — not required, and deliberately not `exists`. RecordPutts falls back to User::defaultPutter() when the id is missing or is not one of the player's putters (deleted since the putt was queued, or someone else's).

This is an offline-first PWA: a phone running a service-worker-cached bundle can post putts queued in localStorage before a field existed. Making a new per-putt field required strands those putts in the client queue forever, because the batch 422s on every retry.

Apply the same rule to any future per-putt field — validate nullable, default on the server.
