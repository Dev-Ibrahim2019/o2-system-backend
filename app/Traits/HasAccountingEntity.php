<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════════
// TRAIT: HasAccountingEntity
// ══════════════════════════════════════════════════════════════════════
// يُضاف لأي Model يمتلك حساباً محاسبياً (Employee, Customer, Supplier)
// يوفر: إنشاء الحساب، الوصول له، كشف الحساب

namespace App\Traits;

trait HasAccountingEntity
{
    /**
     * جلب رصيد الحساب المباشر
     * استخدم هذا للعرض فقط — للتقارير استخدم AccountingService
     */
    public function getAccountBalanceAttribute(): float
    {
        return $this->account?->balance ?? 0.0;
    }

    /**
     * هل الكيان يمتلك حساباً محاسبياً؟
     */
    public function hasAccount(): bool
    {
        return $this->account_id !== null;
    }

    /**
     * Scope: جلب الكيانات التي لديها حسابات
     */
    public function scopeWithAccount($query)
    {
        return $query->whereNotNull('account_id');
    }
}
