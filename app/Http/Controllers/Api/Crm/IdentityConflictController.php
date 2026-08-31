<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\CustomerIdentityConflict;
use App\Services\Crm\CrmCustomerAccessService;
use App\Services\Crm\IdentityConflictService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IdentityConflictController extends Controller
{
    public function __construct(
        private readonly IdentityConflictService $conflicts,
        private readonly CrmCustomerAccessService $access,
    ) {}

    /**
     * GET /api/crm/identity-conflicts
     *
     * Branch-scoped through the customer, exactly like every other CRM read —
     * a ticket must not expose a customer the viewer could not open directly.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                CustomerIdentityConflict::STATUS_OPEN,
                CustomerIdentityConflict::STATUS_RESOLVED,
                CustomerIdentityConflict::STATUS_DISMISSED,
            ])],
            'source_channel' => ['nullable', Rule::in(CustomerIdentityConflict::CHANNELS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = CustomerIdentityConflict::query()
            ->with(['customer:id,name,code,phone,branch_id', 'resolver:id,name', 'order:id,order_number'])
            ->whereIn('customer_id', $this->access->visibleCustomers($request->user())->select('id'))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['source_channel'] ?? null, fn ($q, $c) => $q->where('source_channel', $c))
            ->latest()
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));

        return response()->json(['data' => $page]);
    }

    public function show(Request $request, CustomerIdentityConflict $identityConflict): JsonResponse
    {
        $this->authorizeTicket($request, $identityConflict);

        // Candidates are exposed on read too, not just on resolve: a reviewer
        // may come back to a closed split ticket days later to finish deciding
        // which past orders belonged to the person who was split off. Without
        // this the screen could not tell whether anything is still pending.
        return response()->json([
            'data' => $identityConflict->load([
                'customer:id,name,code,phone,branch_id',
                'resolver:id,name',
                'createdCustomer:id,name,code',
                'order:id,order_number,created_at',
            ]),
            'candidate_orders' => $identityConflict->created_customer_id !== null
                ? $this->conflicts->candidateOrders($identityConflict)
                : [],
        ]);
    }

    /**
     * POST /api/crm/identity-conflicts/{id}/resolve
     */
    public function resolve(Request $request, CustomerIdentityConflict $identityConflict): JsonResponse
    {
        $this->authorizeTicket($request, $identityConflict);

        $data = $request->validate([
            'resolution' => ['required', Rule::in(CustomerIdentityConflict::RESOLUTIONS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $resolved = $this->conflicts->resolve(
            $identityConflict,
            $data['resolution'],
            $request->user(),
            $data['note'] ?? null,
        );

        // Only the two resolutions that split a customer off produce
        // candidates. They are a suggestion for the reviewer, nothing more —
        // no order has moved at this point, and none will until the reviewer
        // names the ones they want through reassign-orders.
        $candidates = $resolved->created_customer_id !== null
            ? $this->conflicts->candidateOrders($resolved)
            : [];

        return response()->json([
            'data' => $resolved,
            'candidate_orders' => $candidates,
        ]);
    }

    /**
     * POST /api/crm/identity-conflicts/{id}/reassign-orders
     *
     * Moves only the orders the reviewer explicitly listed. Separate from
     * resolve() on purpose: deciding which past orders belonged to which
     * person may take a phone call, and the ticket should not have to stay
     * open while that happens.
     */
    public function reassignOrders(Request $request, CustomerIdentityConflict $identityConflict): JsonResponse
    {
        $this->authorizeTicket($request, $identityConflict);

        $data = $request->validate([
            // Required and explicit — there is no "all" shorthand. An empty
            // array is a legitimate decision ("none of these"), recorded as such.
            'order_ids' => ['present', 'array'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
        ]);

        $result = $this->conflicts->reassignOrders($identityConflict, $data['order_ids'], $request->user());

        return response()->json([
            'data' => $identityConflict->fresh(['customer', 'resolver', 'createdCustomer']),
            'reassigned' => $result['reassigned'],
            'left' => $result['left'],
            'candidate_orders' => $this->conflicts->candidateOrders($identityConflict->fresh()),
        ]);
    }

    /**
     * POST /api/crm/identity-conflicts/{id}/dismiss
     *
     * For tickets that should never have been raised (test data, a typo the
     * operator recognises). Distinct from kept_original, which is a real
     * decision that the two names belong to one person.
     */
    public function dismiss(Request $request, CustomerIdentityConflict $identityConflict): JsonResponse
    {
        $this->authorizeTicket($request, $identityConflict);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return response()->json([
            'data' => $this->conflicts->dismiss($identityConflict, $request->user(), $data['note'] ?? null),
        ]);
    }

    private function authorizeTicket(Request $request, CustomerIdentityConflict $conflict): void
    {
        $this->access->authorize($request->user(), $conflict->customer);
    }
}
