<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * من يصل لأي مخزن.
 *
 * القاعدة: المستخدم يصل للمخازن المربوط بها في warehouse_user فقط. يُستثنى
 * super-admin ومن يملك inventory.warehouses.all. لا يكفي أن يكون المخزن في
 * فرع المستخدم — أمين مخزن النصيرات لا يرى مخزن غزة ولو كانا في الشاشة نفسها.
 *
 * كل استعلام أو عملية على مخزن في الوحدة تمر من هنا (المرحلة 2 وما بعدها
 * تستخدم canAccess قبل تسجيل أي حركة).
 */
class WarehouseAccess
{
    public const ALL_PERMISSION = 'inventory.warehouses.all';

    public function hasGlobalAccess(User $user): bool
    {
        return $user->hasRole('super-admin') || $user->can(self::ALL_PERMISSION);
    }

    /** @param  Builder<Warehouse>  $query */
    public function scope(Builder $query, User $user): Builder
    {
        if ($this->hasGlobalAccess($user)) {
            return $query;
        }

        return $query->whereIn('warehouses.id', $this->assignedIds($user));
    }

    public function canAccess(User $user, Warehouse|int $warehouse): bool
    {
        if ($this->hasGlobalAccess($user)) {
            return true;
        }

        $id = $warehouse instanceof Warehouse ? $warehouse->id : $warehouse;

        return DB::table('warehouse_user')
            ->where('user_id', $user->id)
            ->where('warehouse_id', $id)
            ->exists();
    }

    /**
     * مستوى "مشاهدة وتعديل" على المخزن (= القسم في الفرع): مطلوب لكل كتابة (مستندات، جرد، إنتاج).
     * المربوط بمستوى "مشاهدة" يرى فقط؛ وغير المربوط لا يصل أصلاً.
     */
    public function canEdit(User $user, Warehouse|int $warehouse): bool
    {
        if ($this->hasGlobalAccess($user)) {
            return true;
        }

        $id = $warehouse instanceof Warehouse ? $warehouse->id : $warehouse;

        return DB::table('warehouse_user')
            ->where('user_id', $user->id)
            ->where('warehouse_id', $id)
            ->where('access_level', 'edit')
            ->exists();
    }

    /** @return array<int> */
    public function assignedIds(User $user): array
    {
        return DB::table('warehouse_user')
            ->where('user_id', $user->id)
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
