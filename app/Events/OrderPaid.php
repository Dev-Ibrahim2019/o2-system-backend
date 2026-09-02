<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order became fully paid.
 *
 * Fired exactly once per order, from OrderPaymentService::markPaid() and
 * nowhere else — every payment channel routes through that method, so this is
 * the single moment anything that reacts to payment can hang off. Loyalty
 * accrual and referral credit are the intended first consumers; neither
 * exists yet, and this ships with no listeners at all on purpose.
 *
 * Dispatched synchronously inside the request that recorded the payment. That
 * is deliberate for now: the project has no cron running schedule:run and no
 * queue worker (verified, not assumed), so a queued listener would never run.
 * A listener that needs to be queued can declare ShouldQueue itself once a
 * worker exists — this event does not force the choice either way.
 *
 * It is dispatched after the write commits, so a listener that reads the
 * order back sees the paid state rather than the state it is replacing.
 */
class OrderPaid
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Order $order,
        /**
         * Whatever the calling channel knows about the payment — which
         * service recorded it, the invoice behind it, the acting user.
         * Free-form on purpose: the channels differ, and forcing a shared
         * shape now would guess at what a listener that does not exist yet
         * will need.
         */
        public array $context = [],
    ) {}
}
