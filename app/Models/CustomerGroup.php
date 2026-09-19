<?php

namespace App\Models;

use App\Traits\Auditable;
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
    use SoftDeletes, Auditable;

    public const TYPES = ['retail', 'wholesale', 'corporate', 'government', 'service'];

    /**
     * The fixed colour palette the redesign's swatch picker offers — matches
     * --crmx-primary and the other brand-adjacent hues already in crmx.css,
     * so a group's colour never clashes with the rest of the CRM's own
     * tokens the way a free-text hex field could.
     */
    public const COLORS = ['rose', 'orange', 'amber', 'green', 'teal', 'blue', 'indigo', 'purple'];

    protected $fillable = ['name', 'group_type', 'color'];

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
