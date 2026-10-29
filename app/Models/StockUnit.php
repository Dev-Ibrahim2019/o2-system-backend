<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/** وحدة قياس عامة (غرام، كيلو، حبة...). التحويل بين الوحدات معرّف لكل صنف في stock_item_units. */
class StockUnit extends Model
{
    use Auditable;

    protected $fillable = ['name', 'symbol', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
