<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryUsage;
use App\Services\Inventory\WarehouseAccess;
use App\Services\Inventory\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** المخازن وربط المستخدمين بها. كل قراءة وتعديل مقيّد بالمخازن المسموحة للمستخدم. */
class WarehouseController extends InventoryController
{
    public function __construct(
        private WarehouseService $warehouses,
        private WarehouseAccess $access,
        private InventoryUsage $usage,
    ) {}

    /** GET /api/inventory/warehouses */
    public function index(Request $request): JsonResponse
    {
        $list = $this->access->scope(Warehouse::query(), $request->user())
            ->with(['branch:id,name', 'department:id,name,nameAr'])
            ->withCount('users')
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->orderByRaw("type = 'transit'")
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get();

        // أمناء كل مخزن (مستوى "تعديل") — يُعرضون في رأس الصرفية بجانب المخزن.
        $keepers = DB::table('warehouse_user as wu')
            ->join('users as u', 'u.id', '=', 'wu.user_id')
            ->whereIn('wu.warehouse_id', $list->pluck('id'))
            ->where('wu.access_level', 'edit')
            ->orderBy('u.name')
            ->get(['wu.warehouse_id', 'u.name'])
            ->groupBy('warehouse_id');
        $list->each(fn (Warehouse $w) => $w->setAttribute('keepers', $keepers->get($w->id)?->pluck('name')->values()->all() ?? []));

        return $this->success('تم', $list);
    }

    /** GET /api/inventory/warehouses/{warehouse} */
    public function show(Request $request, Warehouse $warehouse): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
            return $denied;
        }

        return $this->success('تم', [
            ...$warehouse->load(['branch:id,name', 'users:id,name,username,branch_id'])->toArray(),
            'has_movements' => $this->usage->warehouseHasMovements($warehouse->id),
        ]);
    }

    /** POST /api/inventory/warehouses */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAr($request, $this->rules());

        return $this->attempt(fn () => $this->success('تمت إضافة المخزن', $this->warehouses->save($data, $request->user()), 201));
    }

    /** PUT /api/inventory/warehouses/{warehouse} */
    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
            return $denied;
        }
        $data = $this->validateAr($request, $this->rules($warehouse));

        return $this->attempt(fn () => $this->success('تم تحديث المخزن', $this->warehouses->save($data, $request->user(), $warehouse)));
    }

    /** DELETE /api/inventory/warehouses/{warehouse} */
    public function destroy(Request $request, Warehouse $warehouse): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
            return $denied;
        }

        return $this->attempt(function () use ($warehouse) {
            $this->warehouses->delete($warehouse);

            return $this->success('تم حذف المخزن');
        });
    }

    /** GET /api/inventory/warehouses/{warehouse}/users — كل المستخدمين مع علم الربط. */
    public function users(Request $request, Warehouse $warehouse): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
            return $denied;
        }
        $levels = DB::table('warehouse_user')->where('warehouse_id', $warehouse->id)->pluck('access_level', 'user_id');
        $assigned = $levels->keys()->map(fn ($id) => (int) $id)->all();

        $users = User::with('branch:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'branch_id'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'branch' => $u->branch?->name,
                'assigned' => in_array($u->id, $assigned, true),
                'access_level' => $levels[$u->id] ?? null,
            ]);

        return $this->success('تم', $users);
    }

    /** PUT /api/inventory/warehouses/{warehouse}/users {user_ids: []} */
    public function syncUsers(Request $request, Warehouse $warehouse): JsonResponse
    {
        if ($denied = $this->denyUnlessCanAccess($request, $warehouse)) {
            return $denied;
        }
        $data = $this->validateAr($request, [
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'levels' => ['sometimes', 'array'],
            'levels.*' => [Rule::in(['view', 'edit'])],
        ]);
        $this->warehouses->syncUsers($warehouse, $data['user_ids'], $data['levels'] ?? []);

        return $this->success('تم تحديث مستخدمي المخزن');
    }

    /** @return array<string, mixed> */
    private function rules(?Warehouse $warehouse = null): array
    {
        $required = $warehouse ? ['sometimes', 'required'] : ['required'];

        return [
            'name' => [...$required, 'string', 'max:150'],
            'code' => [...$required, 'string', 'max:30', Rule::unique('warehouses', 'code')->ignore($warehouse?->id)],
            'type' => ['sometimes', Rule::in([Warehouse::TYPE_STANDARD, Warehouse::TYPE_TRANSIT])],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'is_default_sales' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
