# Changelog

## Unreleased

- Drain mode: `drain`, `undrain` and `drain:status`, a self-expiring per-instance
  marker, `Drain::isDraining()` and the `readiness.drain` middleware.
- Checks: draining, maintenance, database, redis, horizon and stale drain.
- Probe runner with a shared time budget, and a fail-closed access guard.
