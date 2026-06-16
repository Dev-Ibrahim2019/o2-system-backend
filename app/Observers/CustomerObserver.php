<?php

namespace App\Observers;

use App\Models\Customer;

/**
 * ══════════════════════════════════════════════════════════════
 * OBSERVER: CustomerObserver (Disabled)
 * ══════════════════════════════════════════════════════════════
 *
 * تم تعطيل هذا الأوبزيرفر — لم نعد ننشئ حساب GL لكل عميل.
 *
 * الانتقال إلى subledger architecture:
 *   entries.subledger_type = 'customer'
 *   entries.subledger_id   = {customer_id}
 *
 * جميع عمليات العملاء المحاسبية تتم عبر حساب التحكم فقط:
 *   - 1120 (Accounts Receivable) لجميع العملاء
 *   - لا يوجد حسابات فرعية منفصلة لكل عميل
 *
 * @see \App\Services\Accounting\CustomerAccountingService
 */
class CustomerObserver
{
    /**
     * تم إلغاء هذه الدالة — لا ننشئ حسابات GL للعملاء.
     * بدلاً من ذلك، نستخدم subledger في entries table.
     */
    public function created(Customer $customer): void
    {
        // ✅ تم الانتقال إلى subledger بالكامل — لا نحتاج حساب GL
    }
}
