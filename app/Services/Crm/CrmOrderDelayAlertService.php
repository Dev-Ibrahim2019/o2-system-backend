<?php

namespace App\Services\Crm;

use App\Models\CrmOrderDelayAlert;
use App\Models\CrmOrderDelaySetting;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Notifications\OrderDelayedNotification;
use Illuminate\Support\Collection;

/**
 * Evaluates every active order against the CRM's configured delay-alert
 * threshold (CrmOrderDelaySetting) and notifies CRM staff the first time an
 * order crosses it — run every minute by the crm:orders:check-delays
 * schedule (routes/console.php), and once more synchronously whenever the
 * threshold itself changes (Crm\OrderDelaySettingController::update()) so
 * raising or lowering it takes effect immediately instead of waiting for the
 * next tick.
 *
 * Deliberately reads Order directly rather than adding a relation onto the
 * Order model for crm_order_delay_alerts — per the Order Domain Audit
 * (see CrmOrdersQueryService's own doc comment), CRM must stay a read-only
 * projection over the Order domain, never grow it a CRM-shaped appendage.
 *
 * Same terminal-status list as CrmOrdersQueryService — an order is "active"
 * here on the identical definition the Active/Delayed CRM screens already
 * use, not a second, silently different one.
 */
class CrmOrderDelayAlertService
{
    private const TERMINAL_STATUSES = ['paid', 'served', 'cancelled'];

    public function __construct(private readonly CrmCustomerAccessService $access)
    {
    }

    /** @return int how many orders were newly alerted on this run */
    public function checkAndNotify(): int
    {
        $threshold = CrmOrderDelaySetting::current();
        $cutoff = now()->subMinutes($threshold);

        $alreadyAlertedOrderIds = CrmOrderDelayAlert::query()
            ->where('threshold_minutes', $threshold)
            ->pluck('order_id');

        $orders = Order::query()
            ->with('branch:id,name')
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->where('created_at', '<=', $cutoff)
            ->whereNotIn('id', $alreadyAlertedOrderIds)
            ->get();

        foreach ($orders as $order) {
            $this->alert($order, $threshold);
        }

        return $orders->count();
    }

    private function alert(Order $order, int $threshold): void
    {
        // firstOrCreate on the unique (order_id, threshold_minutes) pair —
        // guards against a double notification if this ever runs twice
        // close together (the minute-tick and a manual threshold change
        // landing in the same window).
        $alert = CrmOrderDelayAlert::query()->firstOrCreate(
            ['order_id' => $order->id, 'threshold_minutes' => $threshold],
            ['notified_at' => now()],
        );

        if (! $alert->wasRecentlyCreated) {
            return;
        }

        $notification = new OrderDelayedNotification(
            (int) $order->id,
            $order->order_number,
            (int) $order->created_at->diffInMinutes(now()),
            $threshold,
            $order->branch?->name,
        );

        $this->recipientsFor($order)->each(fn (User $u) => $u->notify($notification));
    }

    /**
     * Everyone who can actually see this order in the CRM: global CRM staff
     * (super-admin, or any user with no branch_id — the same rule
     * CrmCustomerAccessService::isGlobal() already applies to customer
     * access) plus staff at the order's own branch. Scoped, not
     * company-wide — a delay at one branch should not page every branch's
     * accountant/call-center user (contrast
     * ComplaintNotificationService::notifyUrgent(), which is deliberately
     * company-wide for a different reason: complaints frequently carry no
     * branch_id at all).
     */
    private function recipientsFor(Order $order): Collection
    {
        return User::withoutGlobalScope(BranchScope::class)
            ->permission('crm.customer-orders.view')
            ->get()
            ->filter(fn (User $u) => $this->access->isGlobal($u) || (int) $u->branch_id === (int) $order->branch_id);
    }
}
