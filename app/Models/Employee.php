<?php
// app/Models/Employee.php

namespace App\Models;

use App\Traits\HasAccountingEntity;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes, HasAccountingEntity, Auditable;

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
        'status',
        'username',
        'password',
        'pin',
        'permissions',
        'notes',
        'rating',
        'performance',
        'advance_account_id',  // حساب السلف (1130-xxx) — ASSET
        'salary_account_id',   // حساب الراتب (2120-xxx) — LIABILITY
    ];

    protected $hidden = ['password', 'pin'];

    protected $casts = [
        'permissions' => 'array',
        'performance' => 'array',
        'dob'         => 'date',
        'hireDate'    => 'date',
        'salary'      => 'decimal:2',
        'rating'      => 'decimal:1',
    ];

    // ── Relations ──────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * حساب السلف — Asset (مبالغ مستحقة على الموظف للشركة)
     */
    public function advanceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'advance_account_id');
    }

    /**
     * حساب الراتب — Liability (رواتب مستحقة على الشركة للموظف)
     */
    public function salaryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'salary_account_id');
    }
 
    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * رصيد السلف المستحق على الموظف
     */
    public function getOutstandingAdvanceAttribute(): float
    {
        return $this->advanceAccount?->balance ?? 0.0;
    }

    /**
     * الراتب المستحق للموظف (غير المدفوع)
     */
    public function getAccruedSalaryAttribute(): float
    {
        return $this->salaryAccount?->balance ?? 0.0;
    }

    /**
     * صافي المستحق للموظف (راتب - سلف)
     */
    public function getNetPayableAttribute(): float
    {
        return $this->accrued_salary - $this->outstanding_advance;
    }
}
