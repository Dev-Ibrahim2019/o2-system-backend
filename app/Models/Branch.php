<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'location', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'branch_department')
                    ->withPivot('is_active')
                    ->withTimestamps();
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

    public function activeDepartments(): BelongsToMany
    {
        return $this->departments()->wherePivot('is_active', true);
    }
}
