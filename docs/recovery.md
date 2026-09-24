# Indeterminate outcomes and recovery

An indeterminate outcome means the package cannot prove whether side effects happened.

Typical example:

1. application calls a payment provider;
2. provider executes withdrawal;
3. connection/process dies;
4. no local response record is committed.

Blind retry can execute the withdrawal twice. The package therefore blocks automatic execution and requires reconciliation.

Recovery should query a durable external identifier: provider idempotency key, merchant reference, payment ID, or another provider-side lookup key. A resolver returns one of:

- `RecoveryResult::completed($response)` when the external operation definitely succeeded; the package will encode, filter, size-check, and encrypt the response according to the active profile;
- `RecoveryResult::safeToRetry()` when the external operation definitely did not happen;
- `RecoveryResult::indeterminate()` when evidence is still insufficient.

Never return `safeToRetry` merely because a timeout occurred.


Before the external side effect, persist the minimum durable lookup information with `Idempotency::remember([...])` or `Idempotency::reference(...)`. Metadata writes are owner-fenced. Recovery transitions are also compare-and-set guarded by the candidate's observed status and owner token.

`INDETERMINATE` database records are not automatically pruned, even after their normal response TTL. They remain blocked until reconciliation decides `completed` or `safeToRetry`.
