<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyRule extends Model
{
    /**
     * How the matching pass ranks two rules at the same specificity level.
     *
     * Highest priority wins; a tie after that goes to the more recently
     * created rule. A newer rule is the one a manager just wrote — likely a
     * deliberate override of an older one they forgot to deactivate — so
     * "most recent" is the least surprising tiebreak of the two available
     * without inventing a second priority axis.
     */
    public static function specificityWinner(iterable $rules): ?self
    {
        $best = null;

        foreach ($rules as $rule) {
            if ($best === null
                || $rule->priority > $best->priority
                || ($rule->priority === $best->priority && $rule->created_at->gt($best->created_at))
            ) {
                $best = $rule;
            }
        }

        return $best;
    }

    /**
     * scope_type ranked most-specific first, for picking a winner among rules
     * that matched at different scopes simultaneously.
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

    public function exclusions(): HasMany
    {
        return $this->hasMany(LoyaltyRuleExclusion::class, 'rule_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
