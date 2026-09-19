<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\BranchScope;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'employeeId',
        'name',
        'phone',
        'email',
        'address',
        'nationalId',
        'dob',
        'image',
        'branch_id',
        'department_id',
        'jobTitleId',
        'typeId',
        'managerId',
        'hireDate',
        'salary',
        'role',
        'operational_role',
        'vehicle_type',
        'status',
        'username',
        'password',
        'pin',
        'permissions',
        'notes',
        'rating',
        'performance',
        'employee_code',
        'max_active_deliveries',
        // ملاحظة: advance_account_id و salary_account_id حُذفا في النظام الجديد
        // الحسابات تُعرَّف عبر Control Accounts + subledger في entries
    ];

    protected $hidden = ['password', 'pin'];

    protected $casts = [
        'permissions' => 'array',
        'performance' => 'array',
        'dob' => 'date',
        'hireDate' => 'date',
        'salary' => 'decimal:2',
        'rating' => 'decimal:1',
        'max_active_deliveries' => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(EmployeeLoan::class);
    }

    public function driverShifts(): HasMany
    {
        return $this->hasMany(DriverShift::class);
    }

    /** الطلبات المُسنَدة لهذا الموظف كسائق توصيل (orders.driver_id) — لصفحة إدارة الديليفري */
    public function driverOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'driver_id');
    }

    /** "مع التوصيل الآن" — محسوبة دائمًا من وجود طلب OUT_FOR_DELIVERY مُسنَد فعليًا، لا تُضبط يدويًا أبدًا */
    public function hasActiveDelivery(): bool
    {
        return $this->driverOrders()->where('status', 'OUT_FOR_DELIVERY')->exists();
    }

    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class, 'driver_id');
    }

    /** عدد التعيينات النشطة الآن (status=active بجدول delivery_assignments — مصدر الحقيقة للـ
     * workload، وليس عدّ الطلبات OUT_FOR_DELIVERY مباشرة، حتى يبقى متسقًا مع سجل التاريخ الكامل). */
    public function activeDeliveryAssignmentsCount(): int
    {
        return $this->deliveryAssignments()->where('status', 'active')->count();
    }

    /** الحد الأقصى الفعلي لهذا السائق — تجاوز فردي (employees.max_active_deliveries) إن وُجد،
     * وإلا القيمة العامة بـ config('call-center.max_active_deliveries_per_driver') (افتراضي 1). */
    public function maxActiveDeliveries(): int
    {
        return $this->max_active_deliveries ?? (int) config('call-center.max_active_deliveries_per_driver', 1);
    }

    /** هل السائق مؤهّل لتعيين جديد الآن؟ نشط + بشفت مفتوح + دون الحد الأقصى — لا تعتمد على أي
     * حالة "متاح" مُدخَلة يدويًا (القسم 10/19 بالبرومبت: التوفر يُحسب من النشاط التشغيلي فقط). */
    public function isEligibleForNewAssignment(): bool
    {
        return $this->operational_role === 'delivery_driver'
            && $this->status === 'ACTIVE'
            && $this->onShiftNow()
            && $this->activeDeliveryAssignmentsCount() < $this->maxActiveDeliveries();
    }

    /** كود سائق فريد بصيغة DR-### — تسلسل عام (بدون نطاق تاريخي)، نفس نمط generateNumber
     * بالنماذج الأخرى (Invoice/Payment/Order) لكن بدون بادئة تاريخ لأنه معرّف دائم للموظف لا مستند. */
    public static function generateDriverCode(): string
    {
        $last = static::withTrashed()
            ->where('employee_code', 'like', 'DR-%')
            ->orderByDesc('id')
            ->value('employee_code');

        $seq = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }

        return 'DR-' . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }

    /** هل الموظف بشفت مفتوح ومتاح الآن (آخر سجل driver_shifts بلا shift_end وis_available=true) */
    public function isAvailableNow(): bool
    {
        return $this->driverShifts()
            ->whereNull('shift_end')
            ->where('is_available', true)
            ->exists();
    }

    /** هل الموظف بشفت مفتوح حاليًا (بغض النظر عن is_available) — يميّز "غير متاح مؤقتًا/استراحة"
     * (شفت مفتوح لكن is_available=false) عن "غير متصل" (ما في شفت مفتوح أصلاً). */
    public function onShiftNow(): bool
    {
        return $this->driverShifts()->whereNull('shift_end')->exists();
    }

    // ── Subledger Financial Accessors ─────────────────────────────────────────
    // هذه الـ Accessors تحسب مباشرة من entries بدون حسابات مستقلة

    /**
     * مجموع السلف المستحقة على الموظف
     * يحسب من: entries WHERE subledger_type='employee' AND account حساب سلف (1130)
     */
    public function getOutstandingAdvanceAttribute(): float
    {
        $advanceAccountId = $this->getControlAccountId('1130');
        if (! $advanceAccountId) return 0.0;

        $totals = Entry::query()
            ->forSubledgerAccount('employee', $this->id, $advanceAccountId)
            ->whereHas('transaction', fn($q) => $q->where('status', 'posted'))
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        // Asset → debit طبيعي → رصيد = debit - credit
        return (float) ($totals->d - $totals->c);
    }

    /**
     * مجموع الرواتب المستحقة غير المدفوعة
     * يحسب من: entries WHERE subledger_type='employee' AND account حساب رواتب (2120)
     */
    public function getAccruedSalaryAttribute(): float
    {
        $salaryAccountId = $this->getControlAccountId('2120');
        if (! $salaryAccountId) return 0.0;

        $totals = Entry::query()
            ->forSubledgerAccount('employee', $this->id, $salaryAccountId)
            ->whereHas('transaction', fn($q) => $q->where('status', 'posted'))
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        // Liability → credit طبيعي → رصيد = credit - debit
        return (float) ($totals->c - $totals->d);
    }

    /**
     * صافي المبلغ المستحق للموظف (رواتب - سلف)
     */
    public function getNetPayableAttribute(): float
    {
        return max(0.0, $this->accrued_salary - $this->outstanding_advance);
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * جلب ID حساب التحكم من الكود
     * يُخزَّن في cache لتجنب repeated queries
     */
    private function getControlAccountId(string $code): ?int
    {
        static $cache = [];

        if (! isset($cache[$code])) {
            $cache[$code] = Account::where('code', $code)->value('id');
        }

        return $cache[$code];
    }
}
