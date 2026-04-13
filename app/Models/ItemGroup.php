<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemGroup extends Model
{
     protected $fillable = ['name', 'parent_id'];

    // العلاقة مع الأب
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'parent_id');
    }

    // الأبناء المباشرون
    public function children(): HasMany
    {
        return $this->hasMany(ItemGroup::class, 'parent_id');
    }

    // جميع الأبناء بشكل recursive
    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'group_id');
    }

    // الحصول على مسار كامل: ألبان > موزاريلا
    public function getFullPathAttribute(): string
    {
        $path = [$this->name];
        $parent = $this->parent;
        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }
        return implode(' > ', $path);
    }
}
