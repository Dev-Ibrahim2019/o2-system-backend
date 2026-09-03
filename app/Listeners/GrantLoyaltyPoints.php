<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Loyalty\LoyaltyEngine;
use Illuminate\Support\Facades\Log;

/**
 * The first real consumer of OrderPaid. Runs synchronously in the same
 * request that recorded the payment — there is no cron and no queue worker
 * running in this project (confirmed, not assumed), so a queued listener
 * would simply never execute.
 *
 * Failure here must never surface as a failed payment: the money already
 * moved, markPaid() already committed, and a customer must not be told their
 * order failed because a loyalty rule was misconfigured. Errors are logged
 * and swallowed.
 */
class GrantLoyaltyPoints
{
    public function __construct(private LoyaltyEngine $engine) {}

    public function handle(OrderPaid $event): void
    {
        try {
            $this->engine->process($event->order);
        } catch (\Throwable $e) {
            Log::error('Loyalty points grant failed for a paid order.', [
                'order_id' => $event->order->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
