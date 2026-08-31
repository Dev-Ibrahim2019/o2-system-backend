<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A company, family or agency several customers belong to.
 *
 * Holds the business classification that used to be stored per-customer on
 * `customers.category` — where it collided with the Call Center's engagement
 * tags. Membership is optional; an individual customer belongs to no group.
 */
class CustomerGroup extends Model
{
    use SoftDeletes;

    public const TYPES = ['retail', 'wholesale', 'corporate', 'government', 'service'];

    protected $fillable = ['name', 'group_type'];

    /**
     * Occasions owned by the group itself — a founding anniversary, a contract
     * renewal — as opposed to the personal occasions of its members.
     */
    public function occasions(): MorphMany
    {
        return $this->morphMany(CustomerOccasion::class, 'occasionable');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'group_id');
    }
}
