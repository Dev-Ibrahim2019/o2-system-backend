<?php

namespace App\Services\Crm;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only CRM projection over the existing Order domain — per the Order
 * Domain Audit, CRM must never create/confirm/serve/pay/cancel orders, and
 * this service must never become a parallel Order system. It only queries
 * the existing `orders` table (branch-scoped automatically via Order's own
 * BranchScope global scope, same as every other Order query in the app)
 * and shapes the result for monitoring screens (all orders / active /
 * delayed). No new tables, no new statuses, no writes.
 */
class CrmOrdersQueryService
{
    // Terminal states — an order NOT in this set is considered "active"
    // for monitoring purposes. This mirrors the one status-based "done"
    // convention already used elsewhere in the codebase
    // (Customer360QueryService::profile()'s completed_orders_count filter),
    // not a new invented rule.
    private const TERMINAL_STATUSES = ['paid', 'served', 'cancelled'];

    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query = Order::query()
            ->with([
                'branch:id,name',
                'diningTable:id,dining_zone_id,table_number',
                'diningTable.zone:id,name',
                'cashier:id,name',
            ])
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['source'] ?? null, fn (Builder $q, $source) => $q->where('source', $source))
            ->when($filters['payment_status'] ?? null, fn (Builder $q, $paymentStatus) => $this->applyPaymentFilter($q, $paymentStatus))
            ->when($filters['order_type'] ?? null, fn (Builder $q, $type) => $q->where('order_type', $type))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $branchId) => $q->where('branch_id', $branchId))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when(($filters['active'] ?? false), fn (Builder $q) => $q->whereNotIn('status', self::TERMINAL_STATUSES))
            ->when($search !== '', function (Builder $q) use ($search) {
                $q->where(function (Builder $inner) use ($search) {
                    $inner->where('order_number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_phone', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%"));
                });
            });

        // "Delayed" is expressed as elapsed-time-since-created filtered in
        // PHP after fetching the page, not in SQL — see delayed() below for
        // why (the threshold is a display/monitoring concept, not a stored
        // fact, and this endpoint stays a thin projection either way).

        return $query
            ->orderByDesc('created_at')
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }

    /**
     * "Paid" has two real, legitimate representations in the current data —
     * confirmed by direct inspection, not assumed:
     *  - The POS/general settlement path (SettlementEngine::settle(), used
     *    by every order in the dev DB so far) writes `status = 'paid'` and
     *    never touches `payment_status` at all.
     *  - Call Center's separate payment-execution flow
     *    (CallCenterOrderExecutionService) writes `payment_status` through
     *    its own state machine (unpaid/awaiting_confirmation/processing/
     *    paid/failed) and can leave `status` at 'pending' for a while.
     * A filter that only checks `payment_status = 'paid'` returns 0 rows
     * for the (currently more common) first path — that was the bug. This
     * composes both real paths instead of picking one arbitrarily; it does
     * not change what either write-path actually stores.
     */
    private function applyPaymentFilter(Builder $query, string $payment): Builder
    {
        return match ($payment) {
            'paid' => $query->where(fn (Builder $q) => $q->where('status', 'paid')->orWhere('payment_status', 'paid')),
            'unpaid' => $query->where('status', '!=', 'paid')
                ->where(fn (Builder $q) => $q->whereNull('payment_status')->orWhere('payment_status', 'unpaid')),
            // processing / awaiting_confirmation / failed / refunded: real
            // Order::PAYMENT_STATUSES values, currently only ever written by
            // the Call Center path — a direct column match is correct here,
            // there is no POS-side equivalent to compose with.
            default => $query->where('payment_status', $payment),
        };
    }

    /**
     * Active orders (status not in a terminal state) whose elapsed time
     * since creation exceeds $minutes. Elapsed time is measured from
     * `created_at` only — per the Order Domain Audit, no reliable
     * order-level per-status timestamp exists today (confirmed/ready/served
     * timestamps only exist per kitchen ticket, not on the order itself),
     * so this is intentionally "time since the order was placed", not
     * "time in its current stage". The UI must be honest about that.
     */
    public function delayed(User $user, int $minutes, array $filters = []): LengthAwarePaginator
    {
        $threshold = now()->subMinutes($minutes);

        $query = Order::query()
            ->with([
                'branch:id,name',
                'diningTable:id,dining_zone_id,table_number',
                'diningTable.zone:id,name',
                'cashier:id,name',
            ])
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->where('created_at', '<=', $threshold)
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $branchId) => $q->where('branch_id', $branchId))
            ->when($filters['source'] ?? null, fn (Builder $q, $source) => $q->where('source', $source));

        return $query
            ->orderBy('created_at')
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }
}
