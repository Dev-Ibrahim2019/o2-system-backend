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
    /**
     * Only orders created within this many hours are evaluated. An order
     * still "active" days later is a data problem (never closed in the
     * system), not a live delay — without this cap the first run after a
     * quiet period paged everyone once per stale order.
     */
    private const LOOKBACK_HOURS = 24;

    public function __construct(private readonly CrmCustomerAccessService $access)
    {
    }

    /** @return int how many orders were newly alerted on this run */
    public function checkAndNotify(): int
    {
        $threshold = CrmOrderDelaySetting::current();
        $cutoff = now()->subMinutes($threshold);

        // Same "active" definition as the CRM order screens
        // (CrmOrdersQueryService::onlyDelayable(), built on
        // Order::scopeFinalized()). withoutGlobalScopes(): this is a system
        // check — run from a manager's page load it must still cover every
        // branch, not just the viewer's. An order already alerted at ANY
        // threshold is skipped, so changing the threshold no longer re-pages
        // everyone about orders they were already told about.
        $orders = Order::withoutGlobalScopes()
            ->with(['branch' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name')])
            ->tap(fn ($q) => CrmOrdersQueryService::onlyDelayable($q))
            ->whereBetween('created_at', [now()->subHours(self::LOOKBACK_HOURS), $cutoff])
            ->whereNotIn('id', CrmOrderDelayAlert::query()->select('order_id'))
            ->limit(500)
            ->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        // Loaded once per run, not once per order.
        $staff = User::withoutGlobalScope(BranchScope::class)
            ->permission('crm.customer-orders.view')
            ->get();

        $alerted = 0;
        foreach ($orders as $order) {
            $alerted += $this->alert($order, $threshold, $staff) ? 1 : 0;
        }

        return $alerted;
    }

    private function alert(Order $order, int $threshold, Collection $staff): bool
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
            return false;
        }

        $notification = new OrderDelayedNotification(
            (int) $order->id,
            $order->order_number,
            (int) $order->created_at->diffInMinutes(now()),
            $threshold,
            $order->branch?->name,
        );

        $this->recipientsFor($order, $staff)->each(fn (User $u) => $u->notify($notification));

        return true;
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
    private function recipientsFor(Order $order, Collection $staff): Collection
    {
        return $staff->filter(fn (User $u) => $this->access->isGlobal($u) || (int) $u->branch_id === (int) $order->branch_id);
    }
}
