<?php

namespace App\Services\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Turns one paid order into loyalty ledger rows.
 *
 * Two passes, matching the prompt's own split: item-level rules (no
 * min_order_value) price each line, invoice-level rules (min_order_value set)
 * apply one multiplier on top of the summed item points. Both passes share
 * the same scope-matching and exclusion logic; only how a winner is picked
 * among ties differs, because the two levels compete on different axes —
 * specificity for items, the highest satisfied threshold for the invoice.
 */
class LoyaltyEngine
{
    /**
     * Process one order. Returns the customer's earn row, or null when there
     * is nothing to earn — no linked customer, zero eligible total, or an
     * order already processed (checked by source_order_id, so a listener
     * that somehow runs twice can never double-grant).
     */
    public function process(Order $order): ?LoyaltyTransaction
    {
        if (! $order->customer_id) {
            return null;
        }

        if (LoyaltyTransaction::where('source_order_id', $order->id)
            ->where('owner_type', 'customer')
            ->where('type', 'earn')
            ->exists()
        ) {
            return null;
        }

        $customer = Customer::withoutGlobalScopes()->find($order->customer_id);
        if (! $customer) {
            return null;
        }

        $items = $order->items()->get();
        if ($items->isEmpty()) {
            return null;
        }

        $baseRule = $this->baseRule();
        $rules = $this->activeRules();
        $exclusions = $this->exclusionsFor($customer->id);

        $itemResults = $items->map(
            fn (OrderItem $item) => $this->priceItem($item, $customer, $baseRule, $rules, $exclusions)
        );

        $itemPointsTotal = $itemResults->sum('points');

        $invoiceWinner = $this->winningInvoiceRule((float) $order->total, $customer, $rules, $exclusions);
        $finalPoints = $invoiceWinner
            ? $itemPointsTotal * (float) $invoiceWinner->multiplier
            : $itemPointsTotal;

        if ($finalPoints <= 0) {
            return null;
        }

        // Tracking only — does not affect the amount earned. The invoice
        // winner is preferred because it is the rule that most recently
        // touched the total; failing that, the most specific rule among the
        // items' own winners, per the prompt's own tie order (product before
        // category before customer before group before global).
        $trackingRule = $invoiceWinner ?? $this->mostSpecificOf($itemResults->pluck('rule')->filter());

        $earn = LoyaltyTransaction::create([
            'owner_type' => 'customer',
            'owner_id' => $customer->id,
            'type' => 'earn',
            'points' => round($finalPoints, 3),
            'status' => 'confirmed',
            'source_order_id' => $order->id,
            'rule_id' => $trackingRule?->id,
        ]);

        $this->cascadeToGroup($order, $customer, $finalPoints, $itemResults->pluck('rule')->filter(), $invoiceWinner);

        return $earn;
    }

    /**
     * One item's points, and the rule that decided its multiplier.
     *
     * Formula: (item value / base per_amount) × base points_per_amount ×
     * winner multiplier. The rate always comes from the permanent base rule —
     * every other rule leaves points_per_amount/per_amount null and
     * contributes only its multiplier, exactly as the prompt specifies. When
     * the base rule itself is the winner (nothing more specific matched),
     * its own multiplier (default 1.0) applies, which reduces to the plain
     * base formula.
     */
    private function priceItem(
        OrderItem $item,
        Customer $customer,
        LoyaltyRule $baseRule,
        Collection $rules,
        Collection $exclusions,
    ): array {
        $candidates = $rules
            ->filter(fn (LoyaltyRule $r) => $r->isItemLevel())
            ->filter(fn (LoyaltyRule $r) => $this->itemScopeMatches($r, $item, $customer))
            ->reject(fn (LoyaltyRule $r) => $exclusions->contains($r->id));

        $winner = $this->specificityWinner($candidates);
        $value = (float) $item->total;

        $points = $winner
            ? ($value / (float) $baseRule->per_amount) * (float) $baseRule->points_per_amount * (float) $winner->multiplier
            : 0.0;

        return ['points' => $points, 'rule' => $winner];
    }

    /** Whether $rule's scope matches this order item, for the item-level pass. */
    private function itemScopeMatches(LoyaltyRule $rule, OrderItem $item, Customer $customer): bool
    {
        return match ($rule->scope_type) {
            'product' => (int) $rule->scope_id === (int) $item->item_id,
            'category' => (int) $rule->scope_id === (int) $item->department_id,
            'customer' => (int) $rule->scope_id === (int) $customer->id,
            'group' => $customer->group_id !== null && (int) $rule->scope_id === (int) $customer->group_id,
            'global' => true,
        };
    }

    /**
     * The invoice-level winner: highest min_order_value actually satisfied by
     * the order total wins over a lower one — a deliberately different axis
     * from the item pass, because an invoice rule is choosing a threshold
     * tier, not competing on scope specificity. Ties fall back to the same
     * priority-then-recency order the item pass uses.
     */
    private function winningInvoiceRule(
        float $orderTotal,
        Customer $customer,
        Collection $rules,
        Collection $exclusions,
    ): ?LoyaltyRule {
        $candidates = $rules
            ->filter(fn (LoyaltyRule $r) => ! $r->isItemLevel())
            ->filter(fn (LoyaltyRule $r) => $orderTotal >= (float) $r->min_order_value)
            ->filter(fn (LoyaltyRule $r) => $this->invoiceScopeMatches($r, $customer))
            ->reject(fn (LoyaltyRule $r) => $exclusions->contains($r->id));

        $best = null;
        foreach ($candidates as $rule) {
            if ($best === null
                || (float) $rule->min_order_value > (float) $best->min_order_value
                || ((float) $rule->min_order_value === (float) $best->min_order_value && $rule->priority > $best->priority)
                || ((float) $rule->min_order_value === (float) $best->min_order_value && $rule->priority === $best->priority && $rule->created_at->gt($best->created_at))
            ) {
                $best = $rule;
            }
        }

        return $best;
    }

    /** Invoice-level rules are scoped the same way item rules are, minus product/category — a whole order has no single line to match against. */
    private function invoiceScopeMatches(LoyaltyRule $rule, Customer $customer): bool
    {
        return match ($rule->scope_type) {
            'customer' => (int) $rule->scope_id === (int) $customer->id,
            'group' => $customer->group_id !== null && (int) $rule->scope_id === (int) $customer->group_id,
            'global' => true,
            'product', 'category' => false,
        };
    }

    /** Highest specificity wins (product > category > customer > group > global); ties by priority, then recency — LoyaltyRule::SCOPE_RANK is the single source for the order. */
    private function specificityWinner(Collection $candidates): ?LoyaltyRule
    {
        $best = null;
        foreach ($candidates as $rule) {
            if ($best === null) {
                $best = $rule;
                continue;
            }
            $rank = LoyaltyRule::SCOPE_RANK[$rule->scope_type];
            $bestRank = LoyaltyRule::SCOPE_RANK[$best->scope_type];
            if ($rank < $bestRank
                || ($rank === $bestRank && $rule->priority > $best->priority)
                || ($rank === $bestRank && $rule->priority === $best->priority && $rule->created_at->gt($best->created_at))
            ) {
                $best = $rule;
            }
        }

        return $best;
    }

    private function mostSpecificOf(Collection $rules): ?LoyaltyRule
    {
        return $this->specificityWinner($rules);
    }

    /**
     * Cascade a percentage of the customer's final earned points to their
     * group, as one separate ledger line pointing at the same order.
     *
     * Design decision, not stated explicitly by the prompt: when more than
     * one winning rule for this order carries group_cascade_percent (an item
     * winner and the invoice winner could each carry one), the HIGHEST
     * percentage among them is used, applied once to the customer's total
     * final points — not once per rule. The prompt's own worked example
     * ("نصف نقاطه") describes half of the customer's total points from the
     * order, not a fragment tied to one rule's contribution, and summing
     * per-rule fragments would double-count whenever an item rule and the
     * invoice rule both carried a cascade percentage.
     */
    private function cascadeToGroup(
        Order $order,
        Customer $customer,
        float $finalPoints,
        Collection $itemWinners,
        ?LoyaltyRule $invoiceWinner,
    ): void {
        if (! $customer->group_id) {
            return;
        }

        $carriers = $itemWinners
            ->when($invoiceWinner, fn (Collection $c) => $c->push($invoiceWinner))
            ->filter(fn (LoyaltyRule $r) => $r->group_cascade_percent !== null && (float) $r->group_cascade_percent > 0);

        if ($carriers->isEmpty()) {
            return;
        }

        $carrier = $carriers->sortByDesc(fn (LoyaltyRule $r) => (float) $r->group_cascade_percent)->first();
        $cascadePoints = $finalPoints * ((float) $carrier->group_cascade_percent / 100);

        if ($cascadePoints <= 0) {
            return;
        }

        LoyaltyTransaction::create([
            'owner_type' => 'group',
            'owner_id' => $customer->group_id,
            'type' => 'earn',
            'points' => round($cascadePoints, 3),
            'status' => 'confirmed',
            'source_order_id' => $order->id,
            'rule_id' => $carrier->id,
        ]);
    }

    /**
     * The one permanent global base rate: scope_type=global, no
     * min_order_value (item-level), no ends_at (permanent), active, with a
     * real rate. Exactly one must exist — everything in this engine assumes
     * a rate to multiply against, so a missing or ambiguous base rule is a
     * configuration error surfaced loudly rather than silently earning zero.
     */
    private function baseRule(): LoyaltyRule
    {
        // Narrowed to active global rows in SQL (cheap, and the table is
        // small), then judged by LoyaltyRule::isBaseRule() — the single
        // definition also used by LoyaltyRuleController's guards and the
        // `is_base_rule` API field. No second copy of the five-condition
        // shape check lives here anymore.
        $candidates = LoyaltyRule::query()
            ->where('scope_type', 'global')
            ->where('is_active', true)
            ->get()
            ->filter(fn (LoyaltyRule $r) => $r->isBaseRule());

        if ($candidates->isEmpty()) {
            throw new RuntimeException('لا توجد قاعدة ولاء أساسية عامة نشطة ودائمة — لا يمكن حساب النقاط بلا سعر أساسي.');
        }

        if ($candidates->count() > 1) {
            throw new RuntimeException('يوجد أكثر من قاعدة ولاء أساسية عامة دائمة نشطة — هذا غامض ويجب أن يكون هناك واحدة فقط.');
        }

        return $candidates->first();
    }

    /** All active, in-window rules, loaded once per order rather than per item/rule check. */
    private function activeRules(): Collection
    {
        $now = Carbon::now();

        return LoyaltyRule::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->get();
    }

    private function exclusionsFor(int $customerId): Collection
    {
        return \App\Models\LoyaltyRuleExclusion::query()
            ->where('customer_id', $customerId)
            ->pluck('rule_id');
    }
}
