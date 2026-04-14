<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes;

    protected $fillable = ['group_id', 'name', 'unit', 'base_type', 'is_active', 'image'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'group_id');
    }

    public function departmentItems(): HasMany
    {
        return $this->hasMany(DepartmentItem::class);
    }

    // الوصفات التي ينتجها هذا الصنف
    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'output_item_id');
    }

    // الوصفات التي يدخل فيها هذا الصنف كمكوّن
    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    // في كم وصفة يُستخدم هذا الصنف؟
    public function usedInRecipesCount(): int
    {
        return $this->recipeIngredients()->count();
    }
}
