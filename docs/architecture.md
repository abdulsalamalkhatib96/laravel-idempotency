# Architecture

## Identity

The record identity is an HMAC over the operation scope and caller-provided key. The default scope contains stable idempotency namespace, trusted tenant (when configured), authenticated principal, and route operation. This prevents an otherwise identical opaque key from replaying a response across routes, tenants, or authenticated users.

## Claim protocol

1. Resolve and validate key.
2. Resolve principal and tenant.
3. Build canonical fingerprint.
4. Acquire a short distributed claim lock.
5. Read/create/reclaim the record.
6. Release the claim lock.
7. Execute business code outside the lock.
8. Encode the response.
9. Acquire the short claim lock again.
10. Complete only if `status=processing` and `owner_token` still matches.

The long-running operation is represented by a durable `processing` record and owner token; a Redis lock is not held for the whole request.

## Fencing

Every execution receives a cryptographically random owner token. Completion is a conditional update on both state and owner. A stale worker cannot overwrite the result after ownership changes.

## Leases

A processing record has a lease expiration. Lease expiry is not proof that the operation failed. The conservative policy transitions the record to `indeterminate`, requiring reconciliation.

## Limits

No middleware can guarantee distributed exactly-once execution across an external provider and the local database without cooperation from that provider or a transactional protocol. The package therefore makes uncertainty explicit rather than hiding it behind automatic retries.


## Redis record lifetime

Redis `PROCESSING` records are deliberately stored without TTL. A worker crash must not allow the record to disappear and make an unknown operation executable again. After a terminal known state, `COMPLETED` and `FAILED_SAFE` use the configured TTL; `INDETERMINATE` remains a non-expiring tombstone. For operations needing automated orphan enumeration and forensic history, use database records.

## Recovery fencing

Recovery transitions compare the status and owner token that were observed when the candidate was read. If a concurrent actor has reclaimed or changed the record, the reconciliation write is rejected instead of overwriting newer state.
