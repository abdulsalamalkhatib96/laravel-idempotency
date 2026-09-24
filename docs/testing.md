# Testing and failure injection

The normal package suite is:

```bash
composer validate --strict
composer install
composer test
```

The included PHPUnit suite covers canonical fingerprints, HTTP replay, fingerprint conflicts, route and principal isolation, missing keys, explicit safe retry, indeterminate failures, logical TTL reuse, non-pruning of indeterminate tombstones, recovery metadata, recovery completion, recovery compare-and-set fencing, service-level replay, and lock-manager exception propagation.

For a production payment system, also run environment-level concurrency tests against the **same database and lock backend used in production**. In particular, inject failures at these boundaries:

1. after the idempotency record is claimed but before business code starts;
2. after a local database commit but before response persistence;
3. after an external provider accepts the operation but before its response is received;
4. after response encoding but before the idempotency record is completed;
5. after completion is persisted but before the HTTP response reaches the client;
6. while a second node submits the same key concurrently;
7. after the processing lease expires while the original worker is still alive.

The invariant to verify is not “the request always succeeds”. It is: **an unknown outcome must never silently become permission to execute the side effect again**.
