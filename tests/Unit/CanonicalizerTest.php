<?php

namespace Abdulsalam\LaravelIdempotency\Tests\Unit;

use Abdulsalam\LaravelIdempotency\Support\Canonicalizer;
use PHPUnit\Framework\TestCase;

final class CanonicalizerTest extends TestCase
{
    public function test_object_key_order_does_not_change_canonical_json(): void
    {
        $canonicalizer = new Canonicalizer();

        self::assertSame(
            $canonicalizer->json(['amount' => 100, 'currency' => 'AED']),
            $canonicalizer->json(['currency' => 'AED', 'amount' => 100]),
        );
    }

    public function test_list_order_remains_significant(): void
    {
        $canonicalizer = new Canonicalizer();

        self::assertNotSame(
            $canonicalizer->json(['items' => [1, 2, 3]]),
            $canonicalizer->json(['items' => [3, 2, 1]]),
        );
    }

    public function test_nested_paths_can_be_ignored(): void
    {
        $canonicalizer = new Canonicalizer();

        self::assertSame(
            ['payment' => ['amount' => 100]],
            $canonicalizer->forgetPaths([
                'payment' => ['amount' => 100, 'client_timestamp' => 123],
            ], ['payment.client_timestamp']),
        );
    }
}
