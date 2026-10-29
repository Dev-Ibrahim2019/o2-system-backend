<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * مخزن يتبع فرعاً. النوع transit هو المخزن الافتراضي "قيد النقل" بلا فرع.
 * is_default_sales: المخزن الذي يُخصم منه بيع الفرع (واحد لكل فرع، مفروض في القاعدة).
 */
class Warehouse extends Model
{
    use Auditable;

    public const TYPE_STANDARD = 'standard';
    public const TYPE_TRANSIT = 'transit';

    protected $fillable = ['branch_id', 'department_id', 'role', 'name', 'code', 'type', 'is_default_sales', 'is_active', 'notes'];

    /** دور مخزن القسم: مخزن القسم نفسه، أو مواد خام المصنع، أو منتجات المصنع، أو المعرض. */
    public const ROLES = [
        'department' => 'مخزن القسم',
        'factory_raw' => 'مواد خام المصنع',
        'factory_products' => 'منتجات المصنع',
        'showroom' => 'المعرض',
    ];

    /** القسم الذي تُنسب مبيعاته (في فرع المخزن) لهذا المخزن — أساس الاستهلاك النظري لكل مخزن. */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // عمود مولَّد في MySQL — لا يُكتب من التطبيق ولا يُعرض.
    protected $hidden = ['default_sales_branch_key'];

    protected $casts = [
        'is_default_sales' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'warehouse_user')->withPivot('created_at');
    }
}
