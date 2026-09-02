<?php

namespace App\Services\Order;

use App\Events\OrderPaid;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * The one place an order is recorded as paid.
 *
 * Before this existed, three channels wrote "paid" independently and none of
 * them agreed on what that meant:
 *
 *  - SettlementEngine::settle()            → status = 'paid' only
 *  - InvoiceController::storePayment()     → status = 'paid' only, and through
 *                                            $invoice->order()->update(), a
 *                                            relation query that never loads
 *                                            the model, so no model event
 *                                            could ever fire from it
 *  - CallCenterOrderExecutionService       → payment_status only, leaving
 *                                            status at 'pending'
 *
 * The result, measured on the real table: of 35 orders, 31 carried
 * status='paid' with payment_status NULL, 0 carried both, and orders.paid_at
 * was NULL on every single row because no code ever wrote it — while
 * InvoiceDetailsController read it to build a "payment" timeline entry that
 * therefore never appeared.
 *
 * Every channel now calls markPaid(), which writes the financial facts
 * identically everywhere and fires OrderPaid once.
 */
class OrderPaymentService
{
    /**
     * Whether reaching "paid" also ends the order's fulfilment lifecycle.
     *
     * `orders.status` does not mean the same thing in both channels, and that
     * is a real distinction, not an accident:
     *
     *  - POS / general settlement runs AFTER the food is served, so 'paid' is
     *    genuinely the terminal lifecycle state there.
     *  - The Call Center takes payment BEFORE anything is prepared. Full
     *    payment is what releases the order to the kitchen, and
     *    CallCenterOrderExecutionService::releasePhase() moves status to
     *    'confirmed' immediately afterwards. Writing 'paid' there would both
     *    erase where the order actually is and be overwritten moments later.
     *
     * So the flag is opt-in and defaults to false: writing status is the lossy
     * option, and a caller that wants it says so. payment_status and paid_at
     * are written unconditionally either way, because "the money arrived" is
     * the same fact in every channel.
     */
    public const CLOSES_LIFECYCLE = 'closes_lifecycle';

    /**
     * Record an order as fully paid.
     *
     * Idempotent on payment_status: a second call on an already-paid order
     * changes nothing and fires nothing. That matters more than it looks —
     * an order can pass through more than one of these paths (a Call Center
     * order that is later settled again through the invoice screen), and a
     * listener that grants loyalty points must not be handed the same payment
     * twice.
     *
     * @param  array<string, mixed>  $context  Passed through to OrderPaid;
     *                                         CLOSES_LIFECYCLE is read here.
     */
    public function markPaid(Order $order, array $context = []): Order
    {
        $closesLifecycle = (bool) ($context[self::CLOSES_LIFECYCLE] ?? false);

        $fresh = DB::transaction(function () use ($order, $closesLifecycle) {
            // Locked and re-read inside the transaction so two channels
            // settling the same order concurrently cannot both see it as
            // unpaid and both dispatch the event.
            $locked = Order::query()
                ->withoutGlobalScopes()
                ->lockForUpdate()
                ->find($order->getKey());

            if (! $locked || $locked->payment_status === Order::PAYMENT_STATUS_PAID) {
                return null;
            }

            // Assigned directly rather than mass-assigned. paid_at is not in
            // Order::$fillable — on purpose, so it stays out of reach of
            // OrderController@update's mass assignment — and update() drops
            // non-fillable keys silently. The first run of this method proved
            // it: status and payment_status landed, paid_at came back empty
            // with no error at all. That silent drop is the same class of
            // failure this service exists to end.
            $locked->payment_status = Order::PAYMENT_STATUS_PAID;
            $locked->paid_at = now();

            if ($closesLifecycle) {
                $locked->status = 'paid';
            }

            $locked->save();

            return $locked;
        });

        if ($fresh === null) {
            return $order->fresh() ?? $order;
        }

        // afterCommit, not a bare dispatch: InvoiceController::storePayment()
        // already holds an open transaction when it calls this, so the
        // transaction above nests as a savepoint and committing it commits
        // nothing durable. Deferring to the outermost commit means a listener
        // never sees a payment that is later rolled back, and it fires
        // immediately when there is no surrounding transaction at all.
        DB::afterCommit(fn () => OrderPaid::dispatch($fresh, $context));

        // Refreshed from the same instance the caller handed us, so the
        // caller's variable reflects the write it just asked for.
        return $order->fresh() ?? $fresh;
    }
}
