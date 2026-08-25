---
paths:
  - 'app/Http/Requests/**'
---

# Requests

## Keep putts.*.putter nullable on the sync endpoint
StorePuttsRequest validates putts.*.putter as nullable, not required, and RecordPutts falls back to Putter::default().

This is an offline-first PWA: a phone running a service-worker-cached bundle can post putts queued in localStorage before a field existed. Making a new per-putt field required strands those putts in the client queue forever, because the batch 422s on every retry.

Apply the same rule to any future per-putt field — validate nullable, default on the server.
