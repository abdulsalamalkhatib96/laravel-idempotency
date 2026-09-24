<?php

namespace Abdulsalam\LaravelIdempotency\Tests\Feature;

use Abdulsalam\LaravelIdempotency\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;

final class HttpIdempotencyTest extends TestCase
{
    public function test_same_key_and_same_payload_replays_without_second_execution(): void
    {
        $counter = new class { public int $value = 0; };
        $this->app->instance('test.counter', $counter);

        Route::post('/withdraw', function (Request $request) {
            app('test.counter')->value++;
            return response()->json(['ok' => true, 'amount' => $request->integer('amount')], 201);
        })->idempotent();

        $first = $this->postJson('/withdraw', ['amount' => 100], ['Idempotency-Key' => 'abc-123']);
        $second = $this->postJson('/withdraw', ['amount' => 100], ['Idempotency-Key' => 'abc-123']);

        $first->assertCreated()->assertHeader('Idempotency-Status', 'created');
        $second->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        self::assertSame(1, $counter->value);
    }

    public function test_same_key_with_different_payload_returns_422(): void
    {
        Route::post('/withdraw-conflict', fn () => response()->json(['ok' => true]))->idempotent();

        $this->postJson('/withdraw-conflict', ['amount' => 100], ['Idempotency-Key' => 'same-key'])->assertOk();

        $this->postJson('/withdraw-conflict', ['amount' => 200], ['Idempotency-Key' => 'same-key'])
            ->assertStatus(422)
            ->assertJsonPath('type', 'idempotency_fingerprint_conflict');
    }

    public function test_missing_key_returns_400(): void
    {
        Route::post('/missing-key', fn () => response()->json(['ok' => true]))->idempotent();

        $this->postJson('/missing-key', ['amount' => 100])
            ->assertStatus(400)
            ->assertJsonPath('type', 'idempotency_key_missing');
    }

    public function test_same_key_can_be_used_on_different_routes(): void
    {
        Route::post('/route-a', fn () => response()->json(['route' => 'a']))->idempotent();
        Route::post('/route-b', fn () => response()->json(['route' => 'b']))->idempotent();

        $this->postJson('/route-a', [], ['Idempotency-Key' => 'shared'])->assertJson(['route' => 'a']);
        $this->postJson('/route-b', [], ['Idempotency-Key' => 'shared'])->assertJson(['route' => 'b']);
    }

    public function test_unknown_exception_becomes_indeterminate_and_blocks_retry(): void
    {
        $counter = new class { public int $value = 0; };
        $this->app->instance('test.failure.counter', $counter);

        Route::post('/danger', function () {
            app('test.failure.counter')->value++;
            throw new RuntimeException('simulated crash after side effect');
        })->idempotent();

        $this->withoutExceptionHandling();

        try {
            $this->postJson('/danger', [], ['Idempotency-Key' => 'danger-key']);
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            self::assertSame('simulated crash after side effect', $e->getMessage());
        }

        $this->withExceptionHandling();

        $this->postJson('/danger', [], ['Idempotency-Key' => 'danger-key'])
            ->assertStatus(409)
            ->assertJsonPath('type', 'idempotency_result_indeterminate');

        self::assertSame(1, $counter->value);
    }

    public function test_json_object_key_order_does_not_change_fingerprint(): void
    {
        $counter = new class { public int $value = 0; };
        $this->app->instance('test.order.counter', $counter);

        Route::post('/canonical', function () {
            app('test.order.counter')->value++;
            return response()->json(['ok' => true]);
        })->idempotent();

        $this->call('POST', '/canonical', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'canonical-key',
        ], '{"amount":100,"currency":"AED"}')->assertOk();

        $this->call('POST', '/canonical', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'canonical-key',
        ], '{"currency":"AED","amount":100}')->assertHeader('Idempotency-Replayed', 'true');

        self::assertSame(1, $counter->value);
    }

    public function test_same_key_is_isolated_between_authenticated_principals(): void
    {
        $this->app->bind(
            \Abdulsalam\LaravelIdempotency\Contracts\PrincipalResolver::class,
            fn () => new class implements \Abdulsalam\LaravelIdempotency\Contracts\PrincipalResolver {
                public function resolve(Request $request): ?string
                {
                    return $request->header('X-Test-Principal');
                }
            },
        );

        $counter = new class { public int $value = 0; };
        $this->app->instance('test.principal.counter', $counter);

        Route::post('/principal-scope', function () {
            app('test.principal.counter')->value++;
            return response()->json(['execution' => app('test.principal.counter')->value]);
        })->idempotent();

        $this->postJson('/principal-scope', [], [
            'Idempotency-Key' => 'same-key',
            'X-Test-Principal' => 'user:1',
        ])->assertJson(['execution' => 1]);

        $this->postJson('/principal-scope', [], [
            'Idempotency-Key' => 'same-key',
            'X-Test-Principal' => 'user:2',
        ])->assertJson(['execution' => 2]);

        self::assertSame(2, $counter->value);
    }

    public function test_financial_profile_rejects_missing_principal(): void
    {
        Route::post('/financial-without-auth', fn () => response()->json(['ok' => true]))
            ->idempotent('financial');

        $this->postJson('/financial-without-auth', [], ['Idempotency-Key' => 'financial-key'])
            ->assertStatus(401)
            ->assertJsonPath('type', 'idempotency_principal_required');
    }

    public function test_safe_to_retry_exception_allows_same_key_to_execute_again(): void
    {
        $counter = new class { public int $value = 0; };
        $this->app->instance('test.safe.counter', $counter);

        Route::post('/safe-failure', function () {
            app('test.safe.counter')->value++;
            if (app('test.safe.counter')->value === 1) {
                throw new \Abdulsalam\LaravelIdempotency\Exceptions\SafeToRetryException('No side effect occurred.');
            }

            return response()->json(['ok' => true]);
        })->idempotent();

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/safe-failure', [], ['Idempotency-Key' => 'safe-key']);
            self::fail('Expected SafeToRetryException.');
        } catch (\Abdulsalam\LaravelIdempotency\Exceptions\SafeToRetryException) {
        }
        $this->withExceptionHandling();

        $this->postJson('/safe-failure', [], ['Idempotency-Key' => 'safe-key'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        self::assertSame(2, $counter->value);
    }

    public function test_indeterminate_records_are_not_pruned_automatically(): void
    {
        Route::post('/indeterminate-prune', function () {
            throw new RuntimeException('unknown outcome');
        })->idempotent();

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/indeterminate-prune', [], ['Idempotency-Key' => 'prune-key']);
        } catch (RuntimeException) {
        }
        $this->withExceptionHandling();

        $this->app['db']->connection('testing')->table('idempotency_records')->update([
            'expires_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $this->artisan('idempotency:prune')->assertSuccessful();

        self::assertSame(
            1,
            $this->app['db']->connection('testing')->table('idempotency_records')
                ->where('status', 'indeterminate')
                ->count(),
        );
    }

    public function test_expired_completed_record_can_be_reused_even_before_pruner_runs(): void
    {
        $counter = new class { public int $value = 0; };
        $this->app->instance('test.ttl.counter', $counter);

        Route::post('/ttl', function (Request $request) {
            app('test.ttl.counter')->value++;
            return response()->json(['amount' => $request->integer('amount')]);
        })->idempotent();

        $this->postJson('/ttl', ['amount' => 100], ['Idempotency-Key' => 'ttl-key'])
            ->assertJson(['amount' => 100]);

        $this->app['db']->connection('testing')->table('idempotency_records')->update([
            'expires_at' => now()->subSecond()->format('Y-m-d H:i:s'),
        ]);

        $this->postJson('/ttl', ['amount' => 200], ['Idempotency-Key' => 'ttl-key'])
            ->assertJson(['amount' => 200]);

        self::assertSame(2, $counter->value);
    }

    public function test_recovery_metadata_survives_unknown_failure_and_can_complete_record(): void
    {
        Route::post('/recover-me', function () {
            \Abdulsalam\LaravelIdempotency\Facades\Idempotency::remember([
                'withdrawal_id' => 98122,
                'merchant_reference' => 'wd-98122',
            ]);

            throw new RuntimeException('provider timeout after unknown outcome');
        })->idempotent();

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/recover-me', ['amount' => 100], ['Idempotency-Key' => 'recover-key']);
        } catch (RuntimeException) {
        }
        $this->withExceptionHandling();

        $row = $this->app['db']->connection('testing')->table('idempotency_records')->first();
        $metadata = json_decode((string) $row->metadata, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(98122, $metadata['withdrawal_id']);
        self::assertSame('wd-98122', $metadata['merchant_reference']);
        self::assertSame('indeterminate', $row->status);

        $this->app->make(\Abdulsalam\LaravelIdempotency\Recovery\RecoveryManager::class)
            ->register('POST:recover-me', new class implements \Abdulsalam\LaravelIdempotency\Contracts\RecoveryResolver {
                public function recover(\Abdulsalam\LaravelIdempotency\Data\IdempotencyRecord $record): \Abdulsalam\LaravelIdempotency\Recovery\RecoveryResult
                {
                    if (($record->metadata['merchant_reference'] ?? null) !== 'wd-98122') {
                        return \Abdulsalam\LaravelIdempotency\Recovery\RecoveryResult::indeterminate('reference_missing');
                    }

                    return \Abdulsalam\LaravelIdempotency\Recovery\RecoveryResult::completed(
                        new \Illuminate\Http\JsonResponse(['recovered' => true]),
                    );
                }
            });

        $this->artisan('idempotency:recover-orphans')->assertSuccessful();

        $this->postJson('/recover-me', ['amount' => 100], ['Idempotency-Key' => 'recover-key'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJson(['recovered' => true]);
    }

    public function test_recovery_compare_and_set_does_not_overwrite_new_owner(): void
    {
        Route::post('/recovery-cas', function () {
            throw new RuntimeException('unknown outcome');
        })->idempotent();

        $this->withoutExceptionHandling();
        try {
            $this->postJson('/recovery-cas', [], ['Idempotency-Key' => 'cas-key']);
        } catch (RuntimeException) {
        }
        $this->withExceptionHandling();

        $row = $this->app['db']->connection('testing')->table('idempotency_records')->first();
        self::assertSame('indeterminate', $row->status);

        $this->app['db']->connection('testing')->table('idempotency_records')
            ->where('identity_hash', $row->identity_hash)
            ->update([
                'status' => 'processing',
                'owner_token' => 'new-owner',
                'lease_expires_at' => now()->addMinute()->format('Y-m-d H:i:s'),
            ]);

        $body = json_encode(['recovered' => true], JSON_THROW_ON_ERROR);
        $stored = new \Abdulsalam\LaravelIdempotency\Data\StoredResponse(
            status: 200,
            headers: ['content-type' => ['application/json']],
            body: $body,
            encoding: 'plain',
            checksum: hash('sha256', $body),
            size: strlen($body),
        );

        $store = $this->app->make(\Abdulsalam\LaravelIdempotency\Storage\StoreManager::class)->store('database');
        $changed = $store->recoverCompleted(
            $row->identity_hash,
            'indeterminate',
            null,
            $stored,
            new \DateTimeImmutable(),
            (new \DateTimeImmutable())->modify('+1 day'),
        );

        self::assertFalse($changed);
        $current = $this->app['db']->connection('testing')->table('idempotency_records')->first();
        self::assertSame('processing', $current->status);
        self::assertSame('new-owner', $current->owner_token);
    }
}
