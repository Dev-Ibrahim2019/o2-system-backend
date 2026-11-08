<?php

namespace App\Services\Inventory;

use App\Models\Warehouse;

/**
 * لأي مخزن يُنسب البيع: مخزن قسم السطر في فرع الطلب، وإلا مخزن البيع الافتراضي للفرع.
 * مصدر واحد للتحليلات (الاستهلاك النظري) ولخصم البيع، حتى يتطابق الاثنان.
 */
final class SalesWarehouses
{
    /** @return array{0: array<int, array<int, int>>, 1: array<int, int>} [فرع][قسم] => مخزن، وفرع => المخزن الافتراضي */
    public static function map(): array
    {
        $deptMap = [];
        $defaults = [];
        foreach (Warehouse::where('type', Warehouse::TYPE_STANDARD)->get(['id', 'branch_id', 'department_id', 'is_default_sales']) as $w) {
            if ($w->department_id) {
                $deptMap[$w->branch_id][$w->department_id] = $w->id;
            }
            if ($w->is_default_sales) {
                $defaults[$w->branch_id] = $w->id;
            }
        }

        return [$deptMap, $defaults];
    }
}
