<?php

namespace Abdulsalam\LaravelIdempotency\Console;

use Abdulsalam\LaravelIdempotency\ProfileRepository;
use Abdulsalam\LaravelIdempotency\Storage\StoreManager;
use Illuminate\Console\Command;

final class InspectCommand extends Command
{
    protected $signature = 'idempotency:inspect {identity-hash} {--profile=}';
    protected $description = 'Inspect a stored idempotency record by identity hash.';

    public function handle(StoreManager $stores, ProfileRepository $profiles): int
    {
        $profile = $profiles->get($this->option('profile') ?: null);
        $record = $stores->store($profile->store)->find((string) $this->argument('identity-hash'));

        if ($record === null) {
            $this->error('Record not found.');
            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['ID', $record->id],
            ['Identity hash', $record->identityHash],
            ['Status', $record->status->value],
            ['Profile', $record->profile],
            ['Operation', $record->routeSignature],
            ['Attempt', (string) $record->attempt],
            ['Replay count', (string) $record->replayCount],
            ['Started', $record->startedAt->format(DATE_ATOM)],
            ['Lease expires', $record->leaseExpiresAt?->format(DATE_ATOM) ?? '-'],
            ['Completed', $record->completedAt?->format(DATE_ATOM) ?? '-'],
            ['Expires', $record->expiresAt->format(DATE_ATOM)],
            ['HTTP status', $record->response ? (string) $record->response->status : '-'],
            ['Error code', $record->errorCode ?? '-'],
        ]);

        return self::SUCCESS;
    }
}
