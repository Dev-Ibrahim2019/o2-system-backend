<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes;

    protected $fillable = ['department_id', 'name', 'name_ar', 'code', 'image', 'unit', 'price', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function departmentItems(): HasMany
    {
        return $this->hasMany(DepartmentItem::class);
    }
}
