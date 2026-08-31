<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerFinancialProfile;
use App\Models\User;

/**
 * The single way to reach a customer's receivables data.
 *
 * Before this, the eight financial fields sat on the customer row and every
 * endpoint decided for itself whether to hide them — which meant each new
 * endpoint was one forgotten abort_unless() away from a leak, and one of them
 * (CrmController::store()) had already forgotten.
 *
 * Now the data lives in its own table and this class is the gate: read it
 * through here and the permission is checked, or reach past it and you are
 * knowingly bypassing a documented boundary.
 */
class CustomerFinancialProfileService
{
    /**
     * Permissions that grant sight of receivables data.
     *
     * Two, not one, because the two domains that legitimately need it are
     * governed separately in this project: CRM's Customer 360 uses the
     * granular crm.* permission, while the Accounting module is gated on
     * view-accounting / manage-accounting. Requiring only the CRM permission
     * would lock Accounting out of its own screens; requiring only the
     * accounting one would undo the granular CRM gate. Neither permission is
     * created or modified here — both already exist and are used as-is.
     */
    public const READ_PERMISSIONS = [
        'crm.view-customer-financial',
        'view-accounting',
        'manage-accounting',
    ];

    public function canRead(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        foreach (self::READ_PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The profile, or null when the caller may not see it.
     *
     * Returns null rather than throwing so read paths that merely *include*
     * financial data (a customer list, a profile page) can omit the section
     * instead of failing the whole request.
     */
    public function get(?User $user, Customer $customer): ?CustomerFinancialProfile
    {
        if (! $this->canRead($user)) {
            return null;
        }

        return $customer->financialProfile()->first();
    }

    /**
     * The profile, or a 403.
     *
     * For endpoints whose entire purpose is the financial data — there is
     * nothing meaningful to return without it.
     */
    public function getOrFail(?User $user, Customer $customer): CustomerFinancialProfile
    {
        abort_unless($this->canRead($user), 403, 'لا تملك صلاحية عرض البيانات المالية للعميل.');

        return $this->ensure($customer);
    }

    /**
     * A customer created outside the Accounting workflow has no profile row.
     * Materialise one on demand with the same defaults the old columns had,
     * so callers never have to null-check their way through a screen.
     */
    public function ensure(Customer $customer): CustomerFinancialProfile
    {
        return $customer->financialProfile()->firstOrCreate([], [
            'currency' => 'ILS',
            'risk_level' => 'low',
            'credit_limit' => 0,
            'payment_terms' => 'net30',
            'credit_days' => 30,
            'opening_balance' => 0,
            'is_opening_balance_posted' => false,
        ]);
    }

    /**
     * Fold the profile's fields back onto customer models for the response.
     *
     * Accounting's screens have always read `customer.risk_level` and
     * `customer.credit_limit` off the customer object. Moving the columns
     * would have broken them, so for callers that pass the permission check
     * the old response shape is reproduced — and for callers that don't, the
     * fields are simply absent. The leak closes without the Accounting UI
     * having to change.
     *
     * Read-only decoration: the merged attributes are not real columns, so a
     * model must NOT be saved after being passed through here.
     *
     * @param  Customer|iterable<Customer>  $customers
     */
    public function attachTo(?User $user, Customer|iterable $customers): void
    {
        if (! $this->canRead($user)) {
            return;
        }

        $models = $customers instanceof Customer ? [$customers] : $customers;

        foreach ($models as $customer) {
            $profile = $customer->financialProfile;

            foreach (CustomerFinancialProfile::FIELDS as $field) {
                $customer->setAttribute($field, $profile?->{$field});
            }

            $customer->makeVisible(CustomerFinancialProfile::FIELDS);
        }
    }

    public function upsert(Customer $customer, array $data): CustomerFinancialProfile
    {
        $financial = $this->onlyFinancial($data);

        if ($financial === []) {
            return $this->ensure($customer);
        }

        $profile = $this->ensure($customer);
        $profile->update($financial);

        return $profile->refresh();
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>} [identity, financial] */
    public function split(array $data): array
    {
        return [
            array_diff_key($data, array_flip(CustomerFinancialProfile::FIELDS)),
            $this->onlyFinancial($data),
        ];
    }

    public function onlyFinancial(array $data): array
    {
        return array_intersect_key($data, array_flip(CustomerFinancialProfile::FIELDS));
    }
}
