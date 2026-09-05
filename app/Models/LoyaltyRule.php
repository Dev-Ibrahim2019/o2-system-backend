<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyRule extends Model
{
    /**
     * scope_type ranked most-specific first, for picking a winner among rules
     * that matched at different scopes simultaneously. The tie-break logic
     * that actually uses this lives in LoyaltyEngine — this constant is the
     * single source both LoyaltyEngine and any future reader must consult,
     * so it stays on the model rather than duplicated as a local array
     * inside the engine.
     */
    public const SCOPE_RANK = ['product' => 0, 'category' => 1, 'customer' => 2, 'group' => 3, 'global' => 4];

    protected $fillable = [
        'name', 'scope_type', 'scope_id',
        'points_per_amount', 'per_amount', 'multiplier',
        'min_order_value', 'starts_at', 'ends_at', 'priority',
        'is_campaign', 'campaign_target', 'campaign_target_metric',
        'group_cascade_percent', 'is_active', 'created_by',
    ];

    protected $casts = [
        'points_per_amount' => 'decimal:4',
        'per_amount' => 'decimal:4',
        'multiplier' => 'decimal:4',
        'min_order_value' => 'decimal:3',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_campaign' => 'boolean',
        'campaign_target' => 'decimal:3',
        'group_cascade_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Appended to every JSON representation of a rule, so the frontend never
     * has to re-derive "is this the base rule" from the raw columns. Before
     * this, the same five-condition shape check was hand-copied in three
     * places (LoyaltyEngine::baseRule(), LoyaltyRuleController's two guards,
     * and the frontend's own isActiveBaseRule()) with no shared source —
     * exactly the kind of drift risk a computed API field exists to close.
     */
    protected $appends = ['is_base_rule'];

    /** Whether $order's total (needed to test min_order_value) is not required yet — see isWithinWindow(). */
    public function isWithinWindow(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return (! $this->starts_at || $this->starts_at->lte($at))
            && (! $this->ends_at || $this->ends_at->gte($at));
    }

    public function isItemLevel(): bool
    {
        return $this->min_order_value === null;
    }

    /**
     * The shape reserved for the permanent base rule — global scope, no
     * invoice threshold, no expiry, active — WITHOUT requiring a rate to be
     * present. Deliberately separate from isBaseRuleData()/isBaseRule():
     * this is what LoyaltyRuleController checks first, to decide whether an
     * incoming payload needs a rate at all before it decides whether one is
     * missing. Accepts a plain attribute array so it can judge a request
     * payload that is not yet a persisted model, as well as an existing row.
     */
    public static function isBaseRuleShape(array $attrs): bool
    {
        return ($attrs['scope_type'] ?? null) === 'global'
            && ($attrs['min_order_value'] ?? null) === null
            && ($attrs['ends_at'] ?? null) === null
            && (bool) ($attrs['is_active'] ?? true);
    }

    /**
     * The full, single definition of "this is the base rule" — the shape
     * above, plus the two rate columns LoyaltyEngine actually divides and
     * multiplies by. This is the one predicate LoyaltyEngine::baseRule(),
     * LoyaltyRuleController's create/update/delete guards, and the
     * `is_base_rule` API field all now share.
     */
    public static function isBaseRuleData(array $attrs): bool
    {
        return self::isBaseRuleShape($attrs)
            && ($attrs['points_per_amount'] ?? null) !== null
            && ($attrs['per_amount'] ?? null) !== null;
    }

    public function isBaseRule(): bool
    {
        return static::isBaseRuleData([
            'scope_type' => $this->scope_type,
            'min_order_value' => $this->min_order_value,
            'ends_at' => $this->ends_at,
            'points_per_amount' => $this->points_per_amount,
            'per_amount' => $this->per_amount,
            'is_active' => $this->is_active,
        ]);
    }

    public function getIsBaseRuleAttribute(): bool
    {
        return $this->isBaseRule();
    }

    public function exclusions(): HasMany
    {
        return $this->hasMany(LoyaltyRuleExclusion::class, 'rule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
