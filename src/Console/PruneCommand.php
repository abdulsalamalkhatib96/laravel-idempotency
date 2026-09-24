<?php

namespace Abdulsalam\LaravelIdempotency\Console;

use Abdulsalam\LaravelIdempotency\ProfileRepository;
use Abdulsalam\LaravelIdempotency\Storage\StoreManager;
use DateTimeImmutable;
use Illuminate\Console\Command;

final class PruneCommand extends Command
{
    protected $signature = 'idempotency:prune {--profile=} {--store=} {--limit=}';
    protected $description = 'Prune expired completed/safe-to-retry idempotency records.';

    public function handle(StoreManager $stores, ProfileRepository $profiles): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('idempotency.cleanup.batch_size', 1000)));
        $storeNames = $this->storesToPrune($profiles);
        $total = 0;

        foreach ($storeNames as $storeName) {
            $deleted = $stores->store($storeName)->pruneExpired(new DateTimeImmutable(), $limit);
            $total += $deleted;
            $this->line("{$storeName}: pruned {$deleted} record(s).");
        }

        $this->info("Pruned {$total} expired idempotency record(s) in total.");
        return self::SUCCESS;
    }

    /** @return list<string> */
    private function storesToPrune(ProfileRepository $profiles): array
    {
        if (is_string($this->option('store')) && $this->option('store') !== '') {
            return [(string) $this->option('store')];
        }

        if (is_string($this->option('profile')) && $this->option('profile') !== '') {
            return [$profiles->get((string) $this->option('profile'))->store];
        }

        $configured = (array) config('idempotency.profiles', []);
        $stores = [];

        foreach (array_keys($configured) as $name) {
            $stores[] = $profiles->get((string) $name)->store;
        }

        return array_values(array_unique($stores ?: ['database']));
    }
}
