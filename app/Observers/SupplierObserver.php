<?php

namespace App\Observers;

use App\Models\Supplier;
use Illuminate\Support\Facades\Log;

/**
 * ══════════════════════════════════════════════════════════════
 * OBSERVER: SupplierObserver (Disabled)
 * ══════════════════════════════════════════════════════════════
 *
 * تم تعطيل هذا الأوبزيرفر — لم نعد ننشئ حساب GL لكل مورد.
 *
 * الانتقال إلى subledger architecture:
 *   entries.subledger_type = 'supplier'
 *   entries.subledger_id   = {supplier_id}
 *
 * جميع عمليات الموردين المحاسبية تتم عبر حسابات التحكم فقط:
 *   - 2110 (Accounts Payable) لجميع الموردين
 *   - لا يوجد حسابات فرعية منفصلة لكل مورد
 *
 * @see \App\Services\Accounting\SupplierAccountingService
 */
class SupplierObserver
{
    /**
     * تم إلغاء هذه الدالة — لا ننشئ حسابات GL للموردين.
     * بدلاً من ذلك، نستخدم subledger في entries table.
     */
    public function created(Supplier $supplier): void
    {
        // ✅ تم الانتقال إلى subledger بالكامل — لا نحتاج حساب GL
    }
}
