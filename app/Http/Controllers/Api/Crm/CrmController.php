<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerComplaint;
use App\Services\Accounting\CustomerAccountingService;
use App\Services\Crm\CrmCustomerAccessService;
use App\Services\Crm\Customer360QueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmController extends Controller
{
    public function __construct(
        private readonly Customer360QueryService $profiles,
        private readonly CrmCustomerAccessService $access,
        private readonly CustomerAccountingService $accounting,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $customers = $this->access->visibleCustomers($request->user())
            ->when(
                ($validated['branch_id'] ?? null) && $this->access->isGlobal($request->user()),
                fn ($q) => $q->where('branch_id', $validated['branch_id'])
            );
        $from = $validated['date_from'] ?? now()->startOfMonth()->toDateString();
        $to = $validated['date_to'] ?? now()->toDateString();

        $data = [
            'filters' => ['branch_id' => $validated['branch_id'] ?? null, 'date_from' => $from, 'date_to' => $to],
            'customers' => [
                'total' => (clone $customers)->count(),
                'active' => (clone $customers)->where('status', 'active')->count(),
                'new_in_period' => (clone $customers)->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->count(),
            ],
            'operations' => [
                'open_complaints' => CustomerComplaint::query()
                    ->whereIn('customer_id', (clone $customers)->select('id'))
                    ->open()->count(),
            ],
            'financial' => null,
            'permissions' => [
                'can_view_financial' => $request->user()->can('crm.view-customer-financial'),
            ],
        ];

        if ($request->user()->can('crm.view-customer-financial')) {
            $data['financial'] = ['status' => 'available_in_customer_profile'];
        }

        return response()->json(['data' => $data]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,blocked'],
            'category' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->profiles->directory($request->user(), $filters);
        $canFinancial = $request->user()->can('crm.view-customer-financial');
        $page->getCollection()->transform(function (Customer $customer) use ($canFinancial) {
            $item = [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'status' => $customer->status,
                'category' => $customer->category,
                'primary_phone' => $customer->primaryPhone?->phone ?? $customer->phone ?? $customer->mobile,
                'branch' => $customer->branch,
                'orders_count' => $customer->orders_count,
                'open_complaints_count' => $customer->open_complaints_count,
            ];
            if ($canFinancial) {
                $item['balance'] = $this->accounting->getBalance($customer);
            }
            return $item;
        });

        return response()->json(['data' => $page]);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        return response()->json(['data' => $this->profiles->profile($request->user(), $customer)]);
    }

    public function overview(Request $request, Customer $customer): JsonResponse
    {
        return $this->show($request, $customer);
    }

    public function orders(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        return response()->json(['data' => $customer->orders()->with('branch:id,name')->latest()->paginate(15)]);
    }

    public function addresses(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        return response()->json(['data' => $customer->addresses()->orderByDesc('is_default')->latest('last_used_at')->get()]);
    }

    public function complaints(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $query = $customer->complaints()->with(['assignedTo', 'followups'])->latest();
        if (! $request->user()->can('crm.view-sensitive-notes')) {
            $query->where('is_sensitive', false);
        }
        return response()->json(['data' => $query->paginate(15)]);
    }

    public function notes(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        $query = $customer->notes()->with('createdBy:id,name')->latest();
        if (! $request->user()->can('crm.view-sensitive-notes')) {
            $query->where('type', '!=', 'sensitive');
        }
        return response()->json(['data' => $query->paginate(20)]);
    }

    public function occasions(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        return response()->json(['data' => $customer->occasions()->where('is_active', true)->orderBy('date')->get()]);
    }

    public function financial(Request $request, Customer $customer): JsonResponse
    {
        return response()->json(['data' => $this->profiles->financial($request->user(), $customer)]);
    }

    public function statement(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        abort_unless($request->user()->can('crm.view-customer-statement'), 403);
        return response()->json(['data' => $this->accounting->getStatement(
            $customer,
            $request->date_from ?? now()->subYear()->toDateString(),
            $request->date_to ?? now()->toDateString(),
            $this->access->isGlobal($request->user()) ? null : $request->user()->branch_id,
        )]);
    }

    public function aging(Request $request, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);
        abort_unless($request->user()->can('crm.view-customer-financial'), 403);
        return response()->json(['data' => $this->accounting->getAging($customer, $request->as_of)]);
    }
}
