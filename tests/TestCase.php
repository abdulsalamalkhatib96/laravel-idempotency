<?php

namespace Abdulsalam\LaravelIdempotency\Tests;

use Abdulsalam\LaravelIdempotency\IdempotencyServiceProvider;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [IdempotencyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('idempotency.secret', 'testing-idempotency-secret');
        $app['config']->set('idempotency.namespace', 'testing');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('idempotency.database.connection', 'testing');
        $app['config']->set('idempotency.profiles.default.lock_store', 'array');
        $app['config']->set('idempotency.profiles.financial.lock_store', 'array');
        $app['config']->set('idempotency.profiles.financial.encrypt_response', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::connection('testing')->hasTable('idempotency_records')) {
            $migration = require __DIR__.'/../database/migrations/2026_01_01_000000_create_idempotency_records_table.php';
            $migration->up();
        }
    }
}
