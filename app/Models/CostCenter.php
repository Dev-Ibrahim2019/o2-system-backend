<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    protected $fillable = [
        'name',
        'type',
        'parent_id'
    ];

    // 🔹 الأب
    public function parent()
    {
        return $this->belongsTo(CostCenter::class, 'parent_id');
    }

    // 🔹 الأبناء
    public function children()
    {
        return $this->hasMany(CostCenter::class, 'parent_id');
    }

    // شجرة كاملة
    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }
}
