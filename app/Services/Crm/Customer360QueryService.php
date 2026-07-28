<?php

namespace App\Services\Crm;

use App\Models\Customer;
use App\Models\CustomerComplaint;
use App\Models\User;
use App\Services\Accounting\CustomerAccountingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class Customer360QueryService
{
    public function __construct(
        private readonly CrmCustomerAccessService $access,
        private readonly CustomerAccountingService $accounting,
    ) {}

    public function directory(User $user, array $filters): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, ['name', 'created_at', 'status', 'code'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $search = trim((string) ($filters['search'] ?? ''));

        return $this->access->visibleCustomers($user)
            ->with(['branch:id,name', 'primaryPhone:id,customer_id,phone,normalized_phone'])
            ->withCount([
                'orders',
                'complaints as open_complaints_count' => fn ($q) => $q->whereIn('status', [
                    CustomerComplaint::STATUS_NEW,
                    CustomerComplaint::STATUS_OPEN,
                    CustomerComplaint::STATUS_IN_PROGRESS,
                    CustomerComplaint::STATUS_WAITING_CUSTOMER,
                ]),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $digits = preg_replace('/\D+/', '', $search);
                $query->where(function ($q) use ($search, $digits) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%")
                        ->orWhereHas('phones', fn ($phones) => $phones
                            ->where('normalized_phone', 'like', '%'.ltrim($digits, '0').'%'));
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when(
                ($filters['branch_id'] ?? null) && $this->access->isGlobal($user),
                fn ($q) => $q->where('branch_id', $filters['branch_id'])
            )
            ->orderBy($sort, $direction)
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }

    public function profile(User $user, Customer $customer): array
    {
        $this->access->authorize($user, $customer);
        $customer->load([
            'branch:id,name',
            'phones:id,customer_id,phone,normalized_phone,type,is_primary,is_verified',
            'address',
        ])->loadCount([
            'orders',
            'orders as completed_orders_count' => fn ($q) => $q->whereIn('status', ['paid', 'served']),
            'complaints as open_complaints_count' => fn ($q) => $q->open(),
        ]);

        $orderStats = $customer->orders()
            ->selectRaw('AVG(total) as average_order_value, MAX(created_at) as last_order_at')
            ->first();

        return [
            'id' => $customer->id,
            'identity' => [
                'name' => $customer->name,
                'code' => $customer->code,
                'status' => $customer->status,
                'category' => $customer->category,
                'primary_phone' => $customer->primaryPhone?->phone ?? $customer->phone ?? $customer->mobile,
                'phones' => $customer->phones,
                'email' => $customer->email,
                'branch' => $customer->branch,
                'default_address' => $customer->address,
                'loyalty_points' => $customer->loyalty_points ?? null,
            ],
            'summary' => [
                'orders_count' => $customer->orders_count,
                'completed_orders_count' => $customer->completed_orders_count,
                'average_order_value' => round((float) ($orderStats?->average_order_value ?? 0), 3),
                'last_order_at' => $orderStats?->last_order_at,
                'open_complaints_count' => $customer->open_complaints_count,
            ],
            'permissions' => [
                'can_edit' => $user->can('crm.edit-customers'),
                'can_view_financial' => $user->can('crm.view-customer-financial'),
                'can_view_sensitive_notes' => $user->can('crm.view-sensitive-notes'),
            ],
        ];
    }

    public function financial(User $user, Customer $customer): array
    {
        $this->access->authorize($user, $customer);
        abort_unless($user->can('crm.view-customer-financial'), 403);

        $balance = $this->accounting->getBalance($customer);
        $aging = $this->accounting->getAging($customer);

        return [
            'balance' => $balance,
            'credit_limit' => (float) $customer->credit_limit,
            'available_credit' => max(0, (float) $customer->credit_limit - $balance),
            'payment_terms' => $customer->payment_terms,
            'credit_days' => $customer->credit_days,
            'aging' => $aging,
            'legacy_source' => false,
        ];
    }
}
