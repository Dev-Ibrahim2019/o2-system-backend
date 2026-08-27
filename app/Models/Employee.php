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
        'salary_type',
        'hourly_rate',
        'daily_rate',
        'standard_daily_hours',
        'role',
        'status',
        'username',
        'password',
        'pin',
        'permissions',
        'notes',
        'rating',
        'performance',
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
        'hourly_rate' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'standard_daily_hours' => 'decimal:2',
        'rating' => 'decimal:1',
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

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'jobTitleId');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'managerId');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'managerId');
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(EmployeeWorkSchedule::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(EmployeeWithdrawal::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(EmployeePayroll::class);
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
