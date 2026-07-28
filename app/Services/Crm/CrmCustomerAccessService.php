<?php

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CrmCustomerAccessService
{
    public function visibleCustomers(User $user): Builder
    {
        $query = Customer::query();

        if (! $this->isGlobal($user)) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public function authorize(User $user, Customer $customer): void
    {
        abort_unless(
            $this->isGlobal($user) || (int) $customer->branch_id === (int) $user->branch_id,
            403,
            'لا تملك صلاحية الوصول إلى عميل من فرع آخر.'
        );
    }

    public function isGlobal(User $user): bool
    {
        return $user->hasRole('super-admin') || is_null($user->branch_id);
    }
}
