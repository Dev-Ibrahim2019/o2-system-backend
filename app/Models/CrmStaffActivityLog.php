<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر واحد في سجل نشاطات موظفي CRM — انظر CrmStaffActivityLogger.
 * يُكتب مرة ولا يُعدَّل.
 */
class CrmStaffActivityLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'actor_branch_id',
        'action',
        'subject_type',
        'subject_id',
        'subject_branch_id',
        'customer_id',
        'is_flagged',
        'flags',
        'details',
        'ip_address',
    ];

    protected $casts = [
        'is_flagged' => 'boolean',
        'flags' => 'array',
        'details' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScope(Scopes\BranchScope::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
