<?php

namespace Abdulsalam\LaravelIdempotency\Console;

use Abdulsalam\LaravelIdempotency\Enums\IdempotencyStatus;
use Abdulsalam\LaravelIdempotency\Events\IdempotencyRecovered;
use Abdulsalam\LaravelIdempotency\ProfileRepository;
use Abdulsalam\LaravelIdempotency\Contracts\ResponseCodec;
use Abdulsalam\LaravelIdempotency\Recovery\RecoveryManager;
use Abdulsalam\LaravelIdempotency\Recovery\RecoveryOutcome;
use Abdulsalam\LaravelIdempotency\Storage\StoreManager;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

final class RecoverOrphansCommand extends Command
{
    protected $signature = 'idempotency:recover-orphans {--profile=} {--limit=100} {--dry-run}';
    protected $description = 'Inspect expired processing leases and indeterminate records and reconcile them conservatively.';

    public function handle(
        StoreManager $stores,
        ProfileRepository $profiles,
        RecoveryManager $recovery,
        ResponseCodec $codec,
        Dispatcher $events,
    ): int {
        $profile = $profiles->get($this->option('profile') ?: null);
        $store = $stores->store($profile->store);
        $now = new DateTimeImmutable();
        $records = $store->recoveryCandidates($now, max(1, (int) $this->option('limit')), $profile->name);

        if ($records === []) {
            $this->info('No recovery candidates found.');
            return self::SUCCESS;
        }

        foreach ($records as $record) {
            if ($this->option('dry-run')) {
                $this->line("Would reconcile {$record->identityHash} ({$record->status->value}, {$record->routeSignature})");
                continue;
            }

            if (! $recovery->has($record->routeSignature)) {
                if ($record->status === IdempotencyStatus::Processing) {
                    $changed = $store->markIndeterminate(
                        $record->identityHash,
                        $record->ownerToken,
                        'processing_lease_expired',
                        $now,
                    );
                    $changed
                        ? $this->warn("Marked indeterminate: {$record->identityHash}")
                        : $this->warn("Record changed concurrently; no recovery transition applied: {$record->identityHash}");
                } else {
                    $this->warn("No resolver; remains indeterminate: {$record->identityHash}");
                }
                continue;
            }

            try {
                $result = $recovery->recover($record);
            } catch (Throwable $e) {
                $this->error("Recovery resolver failed for {$record->identityHash}: {$e->getMessage()}");
                continue;
            }

            $transitioned = false;

            if ($result->outcome === RecoveryOutcome::Completed && $result->response !== null) {
                try {
                    $storedResponse = $codec->encode($result->response, $profile);
                } catch (Throwable $e) {
                    $this->error("Recovered response is not storable for {$record->identityHash}: {$e->getMessage()}");
                    continue;
                }

                $transitioned = $store->recoverCompleted(
                    $record->identityHash,
                    $record->status->value,
                    $record->ownerToken,
                    $storedResponse,
                    $now,
                    $now->modify('+'.$profile->ttlSeconds.' seconds'),
                );
                $transitioned
                    ? $this->info("Recovered completed: {$record->identityHash}")
                    : $this->warn("Recovery skipped because the record changed concurrently: {$record->identityHash}");
            } elseif ($result->outcome === RecoveryOutcome::SafeToRetry) {
                $transitioned = $store->recoverFailedSafe(
                    $record->identityHash,
                    $record->status->value,
                    $record->ownerToken,
                    $result->reason ?? 'recovered_safe_to_retry',
                    $now,
                    $now->modify('+'.$profile->ttlSeconds.' seconds'),
                );
                $transitioned
                    ? $this->info("Recovered safe-to-retry: {$record->identityHash}")
                    : $this->warn("Recovery skipped because the record changed concurrently: {$record->identityHash}");
            } else {
                if ($record->status === IdempotencyStatus::Processing) {
                    $store->markIndeterminate(
                        $record->identityHash,
                        $record->ownerToken,
                        $result->reason ?? 'recovery_indeterminate',
                        $now,
                    );
                }
                $this->warn("Still indeterminate: {$record->identityHash}");
            }

            if ($transitioned) {
                $events->dispatch(new IdempotencyRecovered($record, $result->outcome));
            }
        }

        return self::SUCCESS;
    }
}
