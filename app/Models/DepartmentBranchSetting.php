<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قالب القسم في الفرع: هل فيه وجبات؟ مصنع؟ معرض؟ وهل هو قسم شاورما (تصنيف اللحوم وصنفا البهار والسيخ)؟
 * قالب واحد قابل للضبط بدل كود منفصل لكل قسم.
 */
class DepartmentBranchSetting extends Model
{
    use Auditable;

    protected $fillable = [
        'department_id', 'branch_id', 'has_meals', 'has_factory', 'has_showroom', 'is_shawarma',
        'meat_category_id', 'spice_item_id', 'skewer_item_id',
    ];

    protected $casts = [
        'has_meals' => 'boolean',
        'has_factory' => 'boolean',
        'has_showroom' => 'boolean',
        'is_shawarma' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** إعداد قسم في فرع، أو null إن لم يُضبط. */
    public static function for(?int $departmentId, ?int $branchId): ?self
    {
        return $departmentId && $branchId
            ? static::where('department_id', $departmentId)->where('branch_id', $branchId)->first()
            : null;
    }
}
