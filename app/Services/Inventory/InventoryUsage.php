<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * هل للصنف أو المخزن حركات مخزنية؟ يمنع الحذف وتغيير الوحدة الأساسية.
 *
 * جدول الحركات من المرحلة 2 (migration 2027_03_02_000001)، وقيد RESTRICT على
 * stock_movements.stock_item_id / warehouse_id هو خط الحماية الثاني في القاعدة.
 * فحص وجود الجدول يبقى حتى لا يفشل الكود إن تراجعت migration المرحلة 2 وحدها.
 */
class InventoryUsage
{
    private const MOVEMENTS_TABLE = 'stock_movements';

    public function itemHasMovements(int $stockItemId): bool
    {
        return $this->exists('stock_item_id', $stockItemId);
    }

    public function warehouseHasMovements(int $warehouseId): bool
    {
        return $this->exists('warehouse_id', $warehouseId);
    }

    private function exists(string $column, int $id): bool
    {
        return Schema::hasTable(self::MOVEMENTS_TABLE)
            && DB::table(self::MOVEMENTS_TABLE)->where($column, $id)->exists();
    }
}
