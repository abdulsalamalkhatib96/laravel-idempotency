# Security

- Raw idempotency keys are not persisted. HMAC-SHA256 is used with the stable `IDEMPOTENCY_SECRET` (falling back to `APP_KEY` only when no dedicated secret is configured).
- Authenticated principal and tenant are part of the default scope.
- The financial profile requires an authenticated principal.
- Response replay uses a strict header allow-list.
- Financial responses are encrypted by default.
- Control characters and oversized keys are rejected.
- File fingerprints use streaming SHA-256 through `hash_file`.
- Never use an attacker-controlled tenant header unless it is independently authenticated/authorized.
- Do not put raw idempotency keys into metrics labels or logs.
- A shared distributed lock backend is mandatory in multi-node production deployments.

- Keep `IDEMPOTENCY_SECRET` stable across deployments. Rotating it changes identity hashes and can make an old retry appear new.
- If encrypted response replay is enabled, plan Laravel encryption-key rotation so old stored responses remain decryptable for their configured TTL.
- `INDETERMINATE` records are tombstones and are intentionally excluded from automatic pruning. Reconcile them explicitly.
- For unauthenticated endpoints, the default scope uses `guest`. If responses are caller-sensitive, bind a trusted `PrincipalResolver`/`TenantResolver` (for example an authenticated API-client identity) instead of treating the client-supplied idempotency key as a secret.
