<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * تسجيل تغييرات العلاقات (التصنيفات، مستخدمو المخزن) في سجل التدقيق الحالي.
 * تعديل الحقول نفسها يسجّله AuditObserver تلقائياً؛ أما الجداول الوسيطة فلا
 * تطلق أحداث نماذج، فتُسجَّل هنا بنفس شكل AuditObserver.
 */
class InventoryAudit
{
    /**
     * @param  array<int>  $old
     * @param  array<int>  $new
     */
    public static function relation(Model $model, string $relation, array $old, array $new): void
    {
        sort($old);
        sort($new);
        if ($old === $new) {
            return;
        }

        AuditLog::insert([
            'auditable_type' => get_class($model),
            'auditable_id' => $model->getKey(),
            'event' => 'updated',
            'old_values' => json_encode([$relation => array_values($old)]),
            'new_values' => json_encode([$relation => array_values($new)]),
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => substr(request()->userAgent() ?? '', 0, 255),
            'created_at' => now(),
        ]);
    }
}
