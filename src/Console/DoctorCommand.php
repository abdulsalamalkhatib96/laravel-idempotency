<?php

namespace Abdulsalam\LaravelIdempotency\Console;

use Abdulsalam\LaravelIdempotency\Enums\IdempotencyStatus;
use Abdulsalam\LaravelIdempotency\ProfileRepository;
use Abdulsalam\LaravelIdempotency\Storage\StoreManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'idempotency:doctor {--profile=}';
    protected $description = 'Validate production idempotency configuration and infrastructure.';

    public function handle(
        ProfileRepository $profiles,
        CacheManager $cache,
        DatabaseManager $db,
        StoreManager $stores,
    ): int {
        $profile = $profiles->get($this->option('profile') ?: null);
        $failures = 0;

        $this->line("Profile: {$profile->name}");

        $checks = [
            ['Idempotency secret configured', (string) config('idempotency.secret') !== '', 'Set IDEMPOTENCY_SECRET for a stable production identity secret.'],
            ['Stable namespace configured', (string) config('idempotency.namespace') !== '', (string) config('idempotency.namespace')],
            ['Orphan policy valid', in_array($profile->orphanPolicy, ['indeterminate', 'safe_retry'], true), $profile->orphanPolicy],
            ['Processing lease > lock TTL', $profile->processingLeaseSeconds > $profile->lockSeconds, "lease={$profile->processingLeaseSeconds}s lock={$profile->lockSeconds}s"],
        ];


        try {
            $stores->store($profile->store);
            $checks[] = ['Record store resolves', true, $profile->store];
        } catch (Throwable $e) {
            $checks[] = ['Record store resolves', false, $e->getMessage()];
        }

        if ($profile->encryptResponse) {
            $checks[] = ['APP_KEY configured for response encryption', (string) config('app.key') !== '', 'Required by Laravel Encrypter.'];
        }

        if ($profile->store === 'database') {
            try {
                $connection = $db->connection(config('idempotency.database.connection'));
                $schema = $connection->getSchemaBuilder();
                $table = (string) config('idempotency.database.table', 'idempotency_records');
                $exists = $schema->hasTable($table);
                $checks[] = ['Database table exists', $exists, $table];

                if ($exists && method_exists($schema, 'getIndexes')) {
                    $indexes = $schema->getIndexes($table);
                    $hasIdentityUnique = false;
                    foreach ($indexes as $index) {
                        $columns = array_map('strtolower', (array) ($index['columns'] ?? []));
                        if (($index['unique'] ?? false) && $columns === ['identity_hash']) {
                            $hasIdentityUnique = true;
                            break;
                        }
                    }
                    $checks[] = ['Unique identity index exists', $hasIdentityUnique, 'identity_hash'];
                }

                if ($exists) {
                    $indeterminate = $connection->table($table)
                        ->where('profile', $profile->name)
                        ->where('status', IdempotencyStatus::Indeterminate->value)
                        ->count();
                    $this->line("Indeterminate records awaiting reconciliation: {$indeterminate}");
                }
            } catch (Throwable $e) {
                $checks[] = ['Database connection', false, $e->getMessage()];
            }
        }

        if ($profile->store === 'redis') {
            try {
                $repository = $cache->store((string) config('idempotency.redis.cache_store', 'redis'));
                $probe = 'idempotency:doctor:record:'.bin2hex(random_bytes(6));
                $repository->put($probe, 'ok', 5);
                $ok = $repository->get($probe) === 'ok';
                $repository->forget($probe);
                $checks[] = ['Redis record store works', $ok, (string) config('idempotency.redis.cache_store', 'redis')];
            } catch (Throwable $e) {
                $checks[] = ['Redis record store works', false, $e->getMessage()];
            }
        }

        try {
            $repository = $cache->store($profile->lockStore);
            $driver = $repository->getStore();
            $shared = ! ($driver instanceof ArrayStore || $driver instanceof FileStore);
            $checks[] = ['Lock backend suitable for multi-node use', $shared, $driver::class];

            $lock = $repository->lock('idempotency:doctor:'.bin2hex(random_bytes(6)), 2);
            $acquired = (bool) $lock->get();
            if ($acquired) {
                $lock->release();
            }
            $checks[] = ['Atomic lock works', $acquired, $profile->lockStore];
        } catch (Throwable $e) {
            $checks[] = ['Atomic lock works', false, $e->getMessage()];
        }

        foreach ($checks as [$name, $ok, $detail]) {
            $this->line(sprintf('%s %-42s %s', $ok ? '✓' : '✗', $name, $detail));
            if (! $ok) {
                $failures++;
            }
        }

        if ($profile->name === 'financial' && $profile->orphanPolicy !== 'indeterminate') {
            $this->warn('Financial profile should normally use orphan_policy=indeterminate.');
            $failures++;
        }

        if ($profile->name === 'financial' && $profile->store === 'redis') {
            $this->warn('Redis-only financial storage is operationally weaker than durable database records plus Redis locks.');
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
