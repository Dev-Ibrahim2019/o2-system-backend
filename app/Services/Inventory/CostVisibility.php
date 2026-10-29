<?php

namespace App\Services\Inventory;

use App\Models\User;

/**
 * إخفاء التكاليف والقيم عمّن لا يملك inventory.costs.view (أمين مخزن يرى الكميات فقط).
 * الإخفاء في الخادم لا في الواجهة، حتى لا تصل القيم أصلاً.
 */
final class CostVisibility
{
    public const PERMISSION = 'inventory.costs.view';

    public static function canView(?User $user): bool
    {
        return (bool) $user?->can(self::PERMISSION);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string>  $fields
     * @return array<string, mixed>
     */
    public static function strip(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = null;
            }
        }

        return $row;
    }
}
