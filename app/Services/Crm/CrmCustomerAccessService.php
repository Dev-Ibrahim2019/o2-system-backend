<?php

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CrmCustomerAccessService
{
    // Deliberately no branch filtering on customer identity: there is no
    // centralized call center — every branch runs its own, and the same
    // customer legitimately calls into (and is served by) more than one of
    // them over time. customers.branch_id only records where a customer was
    // first created; it was never meant to gate who else can see them, and
    // orders/complaints/notes are what actually carries a branch (each is
    // its own operational record, scoped separately — see
    // OrderController/CrmController::orderDetails()'s branch check on the
    // order itself, not the customer). A customer's identity, profile and
    // history are shared across all branches by design.
    public function visibleCustomers(User $user): Builder
    {
        return Customer::query();
    }

    public function authorize(User $user, Customer $customer): void
    {
        // No-op today (see the class-level note above) — kept as a real call
        // site rather than deleted so a future branch-restriction rule has
        // exactly one place to land, and so every caller's intent ("this
        // needs to check the actor may see this customer") stays legible.
    }

    /**
     * The same branch check as authorize(), for a record with no customer to
     * key off — a general complaint (customer_id IS NULL). A null branch_id
     * (unclassified) is left to whoever can reach the record at all: there is
     * no branch to compare against, so this is not a place to invent a rule.
     */
    public function authorizeBranch(User $user, ?string $branchId): void
    {
        abort_unless(
            $this->isGlobal($user) || $branchId === null || (int) $branchId === (int) $user->branch_id,
            403,
            'لا تملك صلاحية الوصول إلى سجل من فرع آخر.'
        );
    }

    public function isGlobal(User $user): bool
    {
        return $user->hasRole('super-admin') || is_null($user->branch_id);
    }
}
