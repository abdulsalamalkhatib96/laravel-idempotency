<?php

namespace Abdulsalam\LaravelIdempotency\Tests\Feature;

use Abdulsalam\LaravelIdempotency\Facades\Idempotency;
use Abdulsalam\LaravelIdempotency\Tests\TestCase;

final class OperationRunnerTest extends TestCase
{
    public function test_service_operation_replays_result(): void
    {
        $executions = 0;

        $first = Idempotency::run('wallet:42', 'cmd-1', ['delta' => 50], function () use (&$executions) {
            $executions++;
            return ['balance' => 150];
        });

        $second = Idempotency::run('wallet:42', 'cmd-1', ['delta' => 50], function () use (&$executions) {
            $executions++;
            return ['balance' => 999];
        });

        self::assertSame(['balance' => 150], $first);
        self::assertSame(['balance' => 150], $second);
        self::assertSame(1, $executions);
    }
}
