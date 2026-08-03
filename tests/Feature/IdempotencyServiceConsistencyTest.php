<?php

namespace Tests\Feature;

use App\Models\IdempotencyRecord;
use App\Models\Order;
use App\Services\Support\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class IdempotencyServiceConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_uses_canonical_hash_and_replays_only_completed_result(): void
    {
        $calls = 0;
        $service = app(IdempotencyService::class);
        $first = $service->execute('legacy', 'same', ['outer' => ['b' => 2, 'a' => 1]], null,
            function () use (&$calls) { $calls++; return ['ok' => true]; }, Order::class, 42);
        $second = $service->execute('legacy', 'same', ['outer' => ['a' => 1, 'b' => 2]], null,
            function () use (&$calls) { $calls++; return ['ok' => false]; }, Order::class, 42);

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(1, $calls);
        $this->assertDatabaseHas('idempotency_records', [
            'scope' => 'legacy', 'key' => 'same', 'status' => 'completed',
            'resource_type' => Order::class, 'resource_id' => 42,
        ]);
    }

    public function test_execute_does_not_return_pending_record_as_success(): void
    {
        $service = app(IdempotencyService::class);
        IdempotencyRecord::create([
            'scope' => 'legacy', 'key' => 'pending',
            'request_hash' => $service->requestHash(['a' => 1]), 'status' => 'pending',
        ]);

        $this->expectException(ConflictHttpException::class);
        $service->execute('legacy', 'pending', ['a' => 1], null, fn () => ['unsafe' => true]);
    }

    public function test_execute_rejects_different_payload_with_conflict(): void
    {
        $service = app(IdempotencyService::class);
        $service->execute('legacy', 'conflict', ['a' => 1], null, fn () => ['ok' => true]);

        $this->expectException(ConflictHttpException::class);
        $service->execute('legacy', 'conflict', ['a' => 2], null, fn () => ['ok' => false]);
    }
}
