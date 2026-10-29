<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * قواعد المخازن:
 *  - المخزن العادي يتبع فرعاً إلزامياً. مخزن "قيد النقل" بلا فرع، ويوجد واحد فقط.
 *  - مخزن البيع الافتراضي واحد لكل فرع (مفروض أيضاً بفهرس فريد في القاعدة).
 *    تعيين مخزن افتراضي جديد يلغي تعيين السابق في المعاملة نفسها.
 *  - لا يُحذف مخزن له حركات؛ يُوقَف فقط. ولا يُوقَف أو يُحذف مخزن البيع الافتراضي.
 */
class WarehouseService
{
    public function __construct(private InventoryUsage $usage, private WarehouseAccess $access) {}

    /** @param  array<string, mixed>  $data */
    public function save(array $data, User $by, ?Warehouse $warehouse = null): Warehouse
    {
        $type = $data['type'] ?? $warehouse?->type ?? Warehouse::TYPE_STANDARD;
        $branchId = array_key_exists('branch_id', $data) ? $data['branch_id'] : $warehouse?->branch_id;
        $isDefault = (bool) ($data['is_default_sales'] ?? $warehouse?->is_default_sales ?? false);
        $isActive = (bool) ($data['is_active'] ?? $warehouse?->is_active ?? true);

        if ($warehouse && $type !== $warehouse->type && $this->usage->warehouseHasMovements($warehouse->id)) {
            throw new InventoryRuleException('لا يمكن تغيير نوع مخزن له حركات.');
        }
        if ($warehouse && (int) $branchId !== (int) $warehouse->branch_id
            && $this->usage->warehouseHasMovements($warehouse->id)) {
            throw new InventoryRuleException('لا يمكن نقل مخزن له حركات إلى فرع آخر.');
        }

        if ($type === Warehouse::TYPE_TRANSIT) {
            $otherTransit = Warehouse::where('type', Warehouse::TYPE_TRANSIT)
                ->when($warehouse, fn ($q) => $q->where('id', '!=', $warehouse->id))
                ->exists();
            if ($otherTransit) {
                throw new InventoryRuleException('يوجد مخزن "قيد النقل" مسبقاً، ولا يُسمح إلا بواحد.');
            }
            $data['branch_id'] = null;
            $data['is_default_sales'] = false;
            $isDefault = false;
        } elseif (! $branchId) {
            throw new InventoryRuleException('يجب اختيار الفرع الذي يتبعه المخزن.', ['branch_id' => ['الفرع مطلوب.']]);
        }

        // القسم المربوط: مبيعاته في فرع المخزن تُنسب لهذا المخزن (قسم واحد ← مخزن واحد في الفرع).
        $departmentId = array_key_exists('department_id', $data) ? $data['department_id'] : $warehouse?->department_id;
        if ($departmentId) {
            if ($type === Warehouse::TYPE_TRANSIT) {
                throw new InventoryRuleException('مخزن "قيد النقل" لا يُربط بقسم.');
            }
            // قسم واحد ← مخزن واحد لكل دور في الفرع (مخزن القسم، مواد المصنع، منتجات المصنع، المعرض).
            $role = $data['role'] ?? $warehouse?->role ?? 'department';
            $taken = Warehouse::where('branch_id', $branchId)
                ->where('department_id', $departmentId)
                ->where('role', $role)
                ->when($warehouse, fn ($q) => $q->where('id', '!=', $warehouse->id))
                ->value('name');
            if ($taken) {
                throw new InventoryRuleException("هذا القسم مربوط مسبقاً بالمخزن \"{$taken}\" في الفرع نفسه.",
                    ['department_id' => ['القسم مربوط بمخزن آخر.']]);
            }
        }

        if ($isDefault && ! $isActive) {
            throw new InventoryRuleException('لا يمكن إيقاف مخزن البيع الافتراضي للفرع. عيّن مخزناً آخر افتراضياً أولاً.');
        }

        return DB::transaction(function () use ($data, $by, $warehouse, $isDefault, $branchId) {
            if ($isDefault) {
                // يُلغى تعيين المخزن الافتراضي السابق للفرع عبر النموذج (لا update جماعي)
                // حتى يُسجَّل التغيير في سجل التدقيق.
                Warehouse::where('branch_id', $branchId)
                    ->where('is_default_sales', true)
                    ->when($warehouse, fn ($q) => $q->where('id', '!=', $warehouse->id))
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (Warehouse $w) => $w->update(['is_default_sales' => false]));
            }

            if ($warehouse) {
                $warehouse->update($data);

                return $warehouse->fresh('branch');
            }

            $created = Warehouse::create($data);

            // من ينشئ مخزناً دون وصول شامل يُربط به تلقائياً، وإلا لن يراه بعد إنشائه.
            if (! $this->access->hasGlobalAccess($by)) {
                $created->users()->attach($by->id, ['created_at' => now()]);
            }

            return $created->load('branch');
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        if ($this->usage->warehouseHasMovements($warehouse->id)) {
            throw new InventoryRuleException('لا يمكن حذف مخزن له حركات. يمكنك إيقافه بدلاً من ذلك.');
        }
        if ($warehouse->is_default_sales) {
            throw new InventoryRuleException('لا يمكن حذف مخزن البيع الافتراضي للفرع. عيّن مخزناً آخر افتراضياً أولاً.');
        }

        DB::transaction(function () use ($warehouse) {
            InventoryAudit::relation($warehouse, 'user_ids', $warehouse->users()->pluck('users.id')->all(), []);
            $warehouse->delete();
        });
    }

    /** @param  array<int>  $userIds */
    /**
     * @param  array<int>  $userIds
     * @param  array<int|string, string>  $levels  user_id => view|edit (الافتراضي edit)
     */
    public function syncUsers(Warehouse $warehouse, array $userIds, array $levels = []): void
    {
        DB::transaction(function () use ($warehouse, $userIds, $levels) {
            $old = $warehouse->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            $new = array_values(array_unique(array_map('intval', $userIds)));
            $warehouse->users()->sync(collect($new)->mapWithKeys(fn ($id) => [$id => [
                'created_at' => now(),
                'access_level' => ($levels[$id] ?? 'edit') === 'view' ? 'view' : 'edit',
            ]])->all());
            InventoryAudit::relation($warehouse, 'user_ids', $old, $new);
        });
    }
}
