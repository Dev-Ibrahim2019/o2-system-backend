<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
     protected $fillable = ['output_item_id', 'name', 'yield_qty', 'yield_unit', 'notes'];

    protected $casts = [
        'yield_qty' => 'decimal:3',
    ];

    // الصنف الناتج عن هذه الوصفة
    public function outputItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'output_item_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    // حساب تكلفة الوصفة بناءً على أسعار المشتريات في فرع معين
    public function calculateCost(string $branchId): float
    {
        return $this->ingredients->sum(function ($ingredient) use ($branchId) {
            $price = DepartmentItem::where('item_id', $ingredient->item_id)
                ->where('branch_id', $branchId)
                ->where('role', 'raw_material')
                ->value('price') ?? 0;
            return $ingredient->quantity * $price;
        });
    }
}
