<?php

namespace App\Services\Pos;

use App\Models\Customer;
use App\Models\CrmSetting;
use App\Services\CallCenter\CustomerResolutionService;
use App\Services\CustomerIdentityService;
use App\Services\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;

/**
 * Turns the name/phone a cashier typed into a real customer_id.
 *
 * Same identity layer the Call Center has always used — CustomerResolutionService
 * to find, CustomerIdentityService to create. Nothing new is invented here; the
 * cashier path simply had no call into it (see the Gate 0 audit:
 * OrderController::store() wrote customer_name/customer_phone as loose text and
 * left customer_id null).
 *
 * FAIL-OPEN, ALWAYS. Every path returns a PosCustomerLink and this class never
 * throws. A cashier has no one standing by to disambiguate a phone number
 * mid-sale, so an unresolvable identity must cost the sale nothing: the order
 * completes as a walk-in and the identity is simply not linked.
 *
 * That is the one place this deliberately differs from the Call Center, which
 * raises a validation error on an ambiguous number — correctly, because a live
 * agent is on the phone and can ask.
 */
class PosCustomerLinkService
{
    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly CustomerResolutionService $resolution,
        private readonly CustomerIdentityService $identity,
    ) {}

    /**
     * Resolve the identity for one cashier order.
     *
     * Returns the outcome, not a bare id, so callers can report WHY no
     * customer was linked without repeating the decision logic.
     */
    public function resolve(
        ?int $customerId,
        ?string $name,
        ?string $phone,
        ?int $branchId,
        // "محلي" (dine_in, billed by table) vs "فوري" (takeaway) on the
        // shared POS screen — confirmed directly with the business as the
        // real, only difference between the "عائلات"/"فوري" cashiers today
        // (see createFromCounter()/sourceFor() below). Callers acting for an
        // unrelated flow that happens to also create dine_in orders
        // (Hospitality's own service) must pass null here, not their real
        // order_type, so those customers keep resolving to 'walk_in' as
        // they always have.
        ?string $orderType = null,
    ): PosCustomerLink {
        // Already resolved by the client (customer picked from a search) —
        // the form request has validated it exists. Nothing to do.
        if ($customerId) {
            return PosCustomerLink::linked($customerId);
        }

        try {
            if (blank($phone)) {
                return PosCustomerLink::skipped(PosCustomerLink::SKIPPED_NO_DATA);
            }

            // Checked before resolve() so a bad number never reaches the
            // throwing normalize() inside it.
            if ($this->phones->tryNormalize($phone) === null) {
                return PosCustomerLink::skipped(PosCustomerLink::SKIPPED_INVALID_PHONE);
            }

            // No lock: lockForUpdate belongs to the Call Center's live-call
            // flow, where an agent holds the row while confirming an order.
            // A cashier's write is a single fast insert and must not hold row
            // locks on the customer table during a rush.
            $resolved = $this->resolution->resolve($phone);

            return match ($resolved['status']) {
                'found' => $this->linkExisting($resolved['customer'], $name, $phone),

                // Shared/duplicated number. The Call Center asks the agent to
                // pick; a cashier cannot be asked, so the order proceeds
                // unlinked rather than failing or guessing wrong.
                'multiple' => PosCustomerLink::skipped(PosCustomerLink::SKIPPED_AMBIGUOUS),

                'not_found' => $this->createFromCounter($name, $phone, $branchId, $orderType),

                default => PosCustomerLink::skipped(PosCustomerLink::SKIPPED_NO_DATA),
            };
        } catch (\Throwable $exception) {
            // Nothing about identity resolution is worth failing a sale over.
            Log::warning('POS customer link skipped', [
                'phone' => $phone,
                'branch_id' => $branchId,
                'exception' => $exception->getMessage(),
            ]);

            return PosCustomerLink::skipped(PosCustomerLink::SKIPPED_ERROR);
        }
    }

    /**
     * Matched an existing customer under a different name.
     *
     * The stored name wins, exactly as before. The discrepancy is handed back
     * to the caller rather than recorded here: identity is resolved before the
     * order transaction opens, so raising the ticket at this point would leave
     * it with source_order_id = NULL — which is precisely the gap the audit
     * found across every cashier ticket. The caller raises it once the order
     * has an id, the same way the Call Center always has.
     */
    private function linkExisting(Customer $customer, ?string $name, string $phone): PosCustomerLink
    {
        return PosCustomerLink::linked($customer->id, [
            'customer' => $customer,
            'name' => $name,
            'phone' => $phone,
        ]);
    }

    /**
     * customers.name is NOT NULL, so a phone with no name cannot become a
     * customer — and inventing a placeholder name would put junk in the CRM
     * that an operator then has to clean up. The order stays unlinked instead.
     */
    private function createFromCounter(?string $name, string $phone, ?int $branchId, ?string $orderType): PosCustomerLink
    {
        if (blank($name)) {
            return PosCustomerLink::skipped(PosCustomerLink::SKIPPED_NO_DATA);
        }

        // crm_settings.auto_register_pos_customers — the one real, enforced
        // CRM setting this path answers to. Off means the sale still
        // completes, exactly like any other "skipped" outcome; it simply
        // never creates the customer record.
        if (! CrmSetting::current()->auto_register_pos_customers) {
            return PosCustomerLink::skipped(PosCustomerLink::SKIPPED_AUTO_REGISTER_DISABLED);
        }

        return PosCustomerLink::linked($this->identity->create([
            'name' => trim($name),
            'phone' => $phone,
            'branch_id' => $branchId,
            'status' => 'active',
            'source' => $this->sourceFor($orderType),
        ], Customer::TYPE_OPERATIONAL)->id);
    }

    /**
     * Both cashiers post to the same endpoint and neither identifies itself
     * directly — but the business confirmed the one thing that DOES tell
     * them apart: order_type on this shared POS screen. "محلي" (dine_in,
     * billed by table) is always the "عائلات" cashier; "فوري" (takeaway) is
     * always the "فوري" cashier. Anything else (delivery, or null when the
     * caller deliberately withholds it for an unrelated flow — see
     * resolve()'s own doc comment) keeps the previous, safe 'walk_in'
     * default rather than guessing.
     */
    private function sourceFor(?string $orderType): string
    {
        return match ($orderType) {
            'dine_in' => 'families',
            'takeaway' => 'fawri',
            default => 'walk_in',
        };
    }
}
