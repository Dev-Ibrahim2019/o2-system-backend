<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * نسخة وصفة سارية من effective_from حتى النسخة التالية للمالك نفسه.
 * المالك منتج منيو (item_id) أو منتج وسيط مخزني (stock_item_id). تُدار عبر RecipeService.
 */
class Recipe extends Model
{
    use Auditable;

    protected $fillable = ['item_id', 'stock_item_id', 'effective_from', 'yield_quantity', 'notes', 'created_by'];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
        'yield_quantity' => 'decimal:6',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
