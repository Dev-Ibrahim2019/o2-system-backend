<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** تصنيف مواد خام — شجرة مستقلة عن أقسام البيع (departments). */
class StockCategory extends Model
{
    use Auditable;

    protected $fillable = ['parent_id', 'name', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(StockItem::class, 'stock_category_item');
    }
}
