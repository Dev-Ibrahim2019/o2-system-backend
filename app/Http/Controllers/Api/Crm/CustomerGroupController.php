<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Services\Crm\CrmCustomerAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Customer groups — the company, family or agency several customers belong to.
 *
 * Groups are shared structure with no branch of their own: customer_groups has
 * no branch_id, so there is nothing to scope reads by, and access rests on the
 * crm.groups.* permissions alone. Member *listings* are a different matter and
 * are branch-scoped through the customers themselves — see customers() below.
 */
class CustomerGroupController extends Controller
{
    public function __construct(
        private readonly CrmCustomerAccessService $access,
    ) {}

    /**
     * GET /api/crm/customer-groups
     *
     * Still the list the customer form's picker reads, now also the list the
     * management screen reads — hence the member count.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CustomerGroup::query()
                ->withCount('customers')
                ->orderBy('name')
                ->get(['id', 'name', 'group_type']),
        ]);
    }

    /**
     * GET /api/crm/customer-groups/{group}
     */
    public function show(CustomerGroup $group): JsonResponse
    {
        return response()->json([
            'data' => $group->loadCount('customers'),
        ]);
    }

    /**
     * POST /api/crm/customer-groups
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));

        return response()->json(['data' => CustomerGroup::create($data)], 201);
    }

    /**
     * PUT /api/crm/customer-groups/{group}
     */
    public function update(Request $request, CustomerGroup $group): JsonResponse
    {
        $group->update($request->validate($this->rules(required: false)));

        return response()->json(['data' => $group->fresh()->loadCount('customers')]);
    }

    /**
     * DELETE /api/crm/customer-groups/{group}
     *
     * Soft delete, with the members detached first.
     *
     * customers.group_id carries ON DELETE SET NULL, but that rule only fires
     * on a real DELETE — a soft delete is an UPDATE, so without this the
     * members would keep pointing at a group that no longer appears anywhere,
     * and the customer form's picker would show them as belonging to nothing
     * it can name. Both writes run in one transaction so a group can never end
     * up deleted with its members still attached, or the reverse.
     *
     * The consequence worth knowing: restoring the group brings back the group,
     * not its membership list. Detaching is what the delete means.
     */
    public function destroy(CustomerGroup $group): JsonResponse
    {
        $detached = DB::transaction(function () use ($group) {
            $count = Customer::where('group_id', $group->id)->update(['group_id' => null]);
            $group->delete();

            return $count;
        });

        return response()->json(['data' => ['deleted' => true, 'detached_customers' => $detached]]);
    }

    /**
     * GET /api/crm/customer-groups/{group}/customers
     *
     * Scoped through visibleCustomers(): a group spans branches, but a reader
     * still only sees the members they could open individually. The group's own
     * customers_count is deliberately unscoped — it describes the group, not
     * the reader's slice of it.
     */
    public function customers(Request $request, CustomerGroup $group): JsonResponse
    {
        $members = $this->access->visibleCustomers($request->user())
            ->where('group_id', $group->id)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'phone', 'branch_id', 'status']);

        return response()->json(['data' => $members]);
    }

    /**
     * POST /api/crm/customer-groups/{group}/customers
     *
     * Writes customers.group_id — the same column the customer form edits.
     * There is no pivot table and no second source: membership is that column,
     * so a change here is immediately what the customer form shows.
     */
    public function addCustomer(Request $request, CustomerGroup $group): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ]);

        $customer = Customer::findOrFail($data['customer_id']);

        // A member the reader could not open is a member they must not move.
        $this->access->authorize($request->user(), $customer);

        $customer->update(['group_id' => $group->id]);

        return response()->json([
            'data' => $customer->only(['id', 'name', 'code', 'group_id']),
        ]);
    }

    /**
     * DELETE /api/crm/customer-groups/{group}/customers/{customer}
     *
     * Clears group_id for that one customer only. A customer who is not in
     * this group is a 404 rather than a silent success — otherwise the caller
     * cannot tell "removed" from "was never there", and a wrong id would look
     * like it worked.
     */
    public function removeCustomer(Request $request, CustomerGroup $group, Customer $customer): JsonResponse
    {
        $this->access->authorize($request->user(), $customer);

        abort_unless(
            (int) $customer->group_id === (int) $group->id,
            404,
            'هذا العميل ليس عضواً في هذه المجموعة.'
        );

        $customer->update(['group_id' => null]);

        return response()->json(['data' => ['removed' => true]]);
    }

    /** One rule set; only presence differs between create and update. */
    private function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'group_type' => [$presence, Rule::in(CustomerGroup::TYPES)],
        ];
    }
}
