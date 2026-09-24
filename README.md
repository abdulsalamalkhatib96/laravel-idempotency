# Laravel Idempotency

Production-grade idempotency for Laravel HTTP requests and critical operations.

This package is designed for the cases where "cache the response by key" is not enough: payments, withdrawals, wallet mutations, webhook handling, retrying clients, multi-node deployments, and operations where a process may die after an external side effect.

## Core guarantees

- `Idempotency-Key` support with strict validation.
- Request fingerprinting with canonical JSON and route/query/body/file inputs.
- Principal + tenant + operation scoping to prevent cross-user replay.
- Database or Redis record storage.
- Laravel distributed atomic locks.
- Owner tokens (fencing) and processing leases.
- Response replay with header allow-listing and optional encryption.
- Fingerprint conflict detection (`422`).
- Concurrent duplicate detection (`409`) with optional bounded waiting.
- Conservative `indeterminate` state after unknown failures.
- Automatic pruning and orphan reconciliation commands.
- Service-level `Idempotency::run(...)` for non-HTTP operations.
- Recovery resolvers for externally reconciled operations.

> Idempotency is not the same as distributed exactly-once execution. If an external provider performs a withdrawal and the PHP process dies before persisting the response, the package deliberately records/returns an indeterminate outcome instead of blindly executing the withdrawal again.

## Installation

```bash
composer require abdulsalam/laravel-idempotency
php artisan vendor:publish --tag=idempotency-config
php artisan vendor:publish --tag=idempotency-migrations
php artisan migrate
```

For production, set a dedicated stable secret and namespace:

```dotenv
IDEMPOTENCY_SECRET=generate-a-long-random-secret-and-keep-it-stable
IDEMPOTENCY_NAMESPACE=my-payments-api
```

`IDEMPOTENCY_SECRET` falls back to `APP_KEY`, but a dedicated secret is safer operationally: rotating `APP_KEY` should not make in-flight idempotency identities look new. Keep the namespace stable across deployments as well.

The package uses Laravel package discovery. If package discovery is disabled, register `Abdulsalam\LaravelIdempotency\IdempotencyServiceProvider` manually.

## HTTP usage

```php
use Illuminate\Support\Facades\Route;

Route::post('/withdraw', WithdrawController::class)
    ->middleware('auth:sanctum')
    ->idempotent('financial');
```

Client:

```http
POST /withdraw HTTP/1.1
Idempotency-Key: 6ecb6ad5-b0ca-47cc-9e71-cd00496a8439
Content-Type: application/json

{"amount":100,"currency":"AED"}
```

A successful first execution receives:

```http
Idempotency-Status: created
```

A later request with the same scoped key and same fingerprint is replayed without executing the controller:

```http
Idempotency-Replayed: true
Idempotency-Status: replayed
```

Reusing the same scoped key with a different request returns `422`. A duplicate while the first execution is still processing returns `409` unless the profile uses bounded `concurrent=wait` behavior.

## Financial profile

The bundled `financial` profile defaults to:

- durable database records;
- Redis-backed locks (configurable);
- authenticated principal required;
- encrypted stored responses;
- seven-day TTL;
- `orphan_policy=indeterminate`.

For a multi-node production system, do not configure a local filesystem or array lock store. Use a shared atomic lock backend such as Redis or a database cache store shared by every node.

## Service-level operations

For jobs, commands, consumers, or service methods that are not directly behind HTTP:

```php
use Abdulsalam\LaravelIdempotency\Facades\Idempotency;

$result = Idempotency::run(
    scope: 'withdrawal:'.$withdrawal->id,
    key: $commandId,
    payload: [
        'amount' => $withdrawal->amount,
        'currency' => $withdrawal->currency,
    ],
    callback: fn () => $paymentService->execute($withdrawal),
    profile: 'financial',
);
```

Service-level return values must be JSON serializable because the result is persisted and replayed. The package returns the JSON-normalized value on the first execution as well as on replay, so objects are replay-stable rather than changing type only on the second call. The service `scope` is part of the isolation boundary, so include any tenant/actor dimension required to prevent cross-caller replay (for example `tenant:17|user:81|withdrawal:98122`).

## Provider propagation

Inside an idempotent HTTP request you can derive a stable, boundary-specific downstream key without exposing the inbound key directly:

```php
$providerKey = Idempotency::boundaryKey('acme-payments');

Http::withHeaders([
    'Idempotency-Key' => $providerKey,
])->post($providerUrl, $payload);
```

This is strongly recommended when the downstream provider supports its own idempotency facility. Boundary keys are derived from the persisted key hash, so the same downstream key can also be reconstructed later during recovery:

```php
$providerKey = Idempotency::boundaryKeyForRecord($record, 'acme-payments');
```

## State machine

```text
NEW
  |
  v
PROCESSING ---- successful response ----> COMPLETED
  |                                      |
  | known pre-side-effect failure        +--> replay
  v
FAILED_SAFE ---- next retry ----> PROCESSING
  |
  |
  + unknown exception / expired lease
  v
INDETERMINATE ---- reconciliation required
```

`FAILED_SAFE` is intentionally narrow. Validation/authentication/authorization failures and the explicit `SafeToRetryException` are treated as known-safe. Unexpected exceptions are conservative and become `INDETERMINATE`.

## Safe-to-retry failures

If application code can prove no external or durable side effect occurred, it may throw:

```php
use Abdulsalam\LaravelIdempotency\Exceptions\SafeToRetryException;

throw new SafeToRetryException('Provider was never called.');
```

Do not use this exception merely because you want retries. Use it only when you can prove the operation did not cross the side-effect boundary.

## Recovery metadata

Before crossing an external side-effect boundary, persist the minimum reference data required to reconcile the operation later:

```php
use Abdulsalam\LaravelIdempotency\Facades\Idempotency;

Idempotency::remember([
    'withdrawal_id' => $withdrawal->id,
    'merchant_reference' => $merchantReference,
]);

// Or a single canonical reference:
Idempotency::reference($merchantReference);

$provider->withdraw(...);
```

Metadata writes are fenced by the active owner token and use the same distributed claim lock. Do not store secrets or unnecessary PII in this metadata.

## Recovery

Register a resolver for an exact operation signature or wildcard pattern:

```php
use Abdulsalam\LaravelIdempotency\Contracts\RecoveryResolver;
use Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord;
use Abdulsalam\LaravelIdempotency\Recovery\RecoveryManager;
use Abdulsalam\LaravelIdempotency\Recovery\RecoveryResult;

final class WithdrawalRecovery implements RecoveryResolver
{
    public function recover(IdempotencyRecord $record): RecoveryResult
    {
        $reference = $record->metadata['merchant_reference'] ?? null;

        // Query the payment provider with the durable merchant reference.
        // Return completed(response(...)), safeToRetry(...), or indeterminate(...).
    }
}

app(RecoveryManager::class)->register('POST:withdraw', new WithdrawalRecovery());

// Service operations may use wildcards:
app(RecoveryManager::class)->register('SERVICE:withdrawal:*', new WithdrawalRecovery());
```

Then:

```bash
php artisan idempotency:recover-orphans --profile=financial
```

If there is no registered recovery resolver, expired processing records are marked indeterminate, never automatically executed again.

## Commands

```bash
php artisan idempotency:doctor --profile=financial
php artisan idempotency:prune --limit=1000
php artisan idempotency:prune --profile=financial --limit=1000
php artisan idempotency:inspect <identity-hash> --profile=financial
php artisan idempotency:recover-orphans --profile=financial --limit=100
php artisan idempotency:recover-orphans --profile=financial --dry-run
```

Database pruning is registered automatically as an hourly Laravel scheduled command when `idempotency.cleanup.enabled=true`. Your application must still run Laravel's scheduler (`schedule:run` / `schedule:work`). You may also register explicit schedules, for example:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('idempotency:prune')
    ->hourly()
    ->onOneServer();

Schedule::command('idempotency:recover-orphans --profile=financial')
    ->everyFiveMinutes()
    ->onOneServer();
```

## Custom tenant resolution

The default resolver optionally reads a configured tenant header. In serious multi-tenant applications, bind your own trusted resolver instead of accepting a tenant ID directly from an untrusted header:

```php
$this->app->bind(
    \Abdulsalam\LaravelIdempotency\Contracts\TenantResolver::class,
    App\Idempotency\TenantResolver::class,
);
```

## Response replay rules

Stored headers use an allow-list. `Set-Cookie`, authorization, tracing IDs, and server-generated request IDs are not replayed by default. Streamed and binary responses are rejected because replaying them as ordinary buffered responses is unsafe.

`financial` encrypts the stored response body with Laravel's encrypter. Raw idempotency keys are never persisted; the package persists HMAC-derived key and identity hashes.

## Fingerprints

The default fingerprint includes:

- HTTP method;
- operation/route signature;
- route parameters;
- canonicalized query parameters;
- canonicalized JSON or form data;
- file metadata and SHA-256 content hashes for uploads;
- optional configured request headers.

JSON object key order does not affect the fingerprint. Array order remains significant. Ignored body/query paths are configurable, but ignoring business-significant fields can defeat conflict detection and should be used cautiously. For critical HTTP operations, prefer stable named routes: changing an unnamed route URI changes the operation scope and therefore the idempotency identity.

## Storage guidance

### Database records + Redis lock

Recommended for payment and wallet operations. The database gives durable forensic history while Redis provides a low-latency distributed lock.

### Redis records

Useful for lower-risk high-throughput APIs. `PROCESSING` and `INDETERMINATE` Redis records intentionally do **not** expire automatically: silently forgetting a crashed/unknown execution can permit a duplicate side effect. `COMPLETED` and `FAILED_SAFE` records receive the configured TTL. Redis-only storage does not provide database-style orphan scanning through `idempotency:recover-orphans` because generic Laravel cache APIs intentionally do not scan keys, so long-lived orphan records need an application-specific reconciliation/cleanup strategy. For financial workflows, prefer durable database records plus a shared Redis lock.

## Custom storage

Database and Redis stores are built in. Additional stores can be registered without modifying package code:

```php
use Abdulsalam\LaravelIdempotency\Storage\StoreManager;

app(StoreManager::class)->extend('dynamodb', function ($app) {
    return $app->make(App\Idempotency\DynamoDbIdempotencyStore::class);
});
```

The custom store must implement `IdempotencyStore`.

All custom stores must preserve the same conditional-transition guarantees: claim/reclaim/completion/recovery writes must not overwrite a newer owner or state.

## Observability

The package dispatches events that can feed logs, metrics, tracing, Sentry, OpenTelemetry, or Prometheus adapters without coupling the core to one monitoring vendor:

- `IdempotencyRequestClaimed`
- `IdempotencyRequestReplayed`
- `IdempotencyRequestCompleted`
- `IdempotencyConflictDetected`
- `IdempotencyBecameIndeterminate`
- `IdempotencyRecovered`

Do not use the raw idempotency key as a metrics label. Prefer route/profile/status dimensions to avoid high-cardinality metrics and secret-like log data.

## Production checklist

Run:

```bash
php artisan idempotency:doctor --profile=financial
```

Also verify operationally that:

1. every application node shares the same lock backend;
2. the processing lease is longer than the expected request execution time;
3. the idempotency TTL is at least as long as clients may retry;
4. external payment providers receive a stable provider-side idempotency/merchant reference;
5. unknown provider outcomes have an explicit reconciliation path;
6. pruning is scheduled and runs on one server;
7. logs never include raw keys or decrypted stored responses.

## Testing

```bash
composer install
composer test
```

The included suite covers replay, fingerprint conflicts, route/principal isolation, safe retry, unknown exceptions becoming indeterminate, recovery metadata and fencing, service-level replay, and lock exception propagation. See `docs/testing.md` for the required real-infrastructure concurrency/failure-injection matrix.

## License

MIT.
