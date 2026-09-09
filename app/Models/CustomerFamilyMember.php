<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerFamilyMember extends Model
{
    use SoftDeletes;

    public const RELATIONSHIPS = ['spouse', 'child', 'parent', 'sibling', 'other'];

    /** Arabic labels for the birthday-occasion title — see CustomerIdentityService::syncFamilyMemberBirthdayOccasion(). */
    public const RELATIONSHIP_LABELS = [
        'spouse' => 'زوج/زوجة',
        'child' => 'ابن/ابنة',
        'parent' => 'أحد الوالدين',
        'sibling' => 'أخ/أخت',
        'other' => 'أخرى',
    ];

    protected $fillable = [
        'customer_id',
        'name',
        'relationship',
        'birth_date',
        'occasion_id',
        'created_by',
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The birthday occasion this member's birth_date created, if any — see
     * the migration's comment for why this link exists (occasion_type=
     * 'birthday' alone can't tell two family members' occasions apart the
     * way it can for a customer's own single birthday).
     */
    public function occasion(): BelongsTo
    {
        return $this->belongsTo(CustomerOccasion::class, 'occasion_id');
    }
}
