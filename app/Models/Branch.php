<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'location', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function departmentItems(): HasMany
    {
        return $this->hasMany(DepartmentItem::class);
    }

    // جلب أصناف البيع لهذا الفرع مباشرة
    public function saleItems()
    {
        return $this->departmentItems()
                    ->where('role', 'sale')
                    ->where('is_active', true)
                    ->with('item.group');
    }
}
