# Changelog

## 1.0.0 - 2026-09-23

- Initial production-oriented release.
- HTTP route middleware and `Route::idempotent()` macro.
- Database and Redis record stores.
- Distributed lock integration.
- Canonical request fingerprinting and file hashing.
- Scoped identities with principal/tenant isolation.
- Owner fencing, processing leases, replay and conflict detection.
- Indeterminate state and orphan recovery infrastructure.
- Service-level `Idempotency::run()` API.
- Doctor, inspect, prune and recover-orphans commands.
