<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRuleExclusion extends Model
{
    protected $fillable = ['rule_id', 'customer_id'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(LoyaltyRule::class, 'rule_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
