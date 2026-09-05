<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyRuleExclusion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD for loyalty_rules and their per-customer exclusions.
 *
 * Deliberately does not touch LoyaltyEngine or GrantLoyaltyPoints — this is
 * the administration surface over the rows that engine already reads live on
 * every paid order. Nothing here changes how a rule is scored.
 */
class LoyaltyRuleController extends Controller
{
    private const SCOPE_TYPES = ['global', 'customer', 'group', 'category', 'product'];
    private const CAMPAIGN_METRICS = ['spend', 'points', 'order_count'];

    /**
     * GET /api/crm/loyalty/rules
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'scope_type' => ['nullable', Rule::in(self::SCOPE_TYPES)],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = LoyaltyRule::query()
            ->when($filters['scope_type'] ?? null, fn ($q, $type) => $q->where('scope_type', $type))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->with('creator:id,name')
            ->latest()
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));

        return response()->json(['data' => $page]);
    }

    /**
     * POST /api/crm/loyalty/rules
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(required: true));
        $this->assertBaseRuleInvariant($data);

        $rule = LoyaltyRule::create($data + ['created_by' => $request->user()->id]);

        return response()->json(['data' => $rule], 201);
    }

    /**
     * PUT /api/crm/loyalty/rules/{rule}
     */
    public function update(Request $request, LoyaltyRule $rule): JsonResponse
    {
        $data = $request->validate($this->rules(required: false));

        // Merged onto the existing row before checking the invariant: a
        // partial update (e.g. only toggling is_active) must be judged by
        // what the row will actually look like afterwards, not by the
        // partial payload alone.
        $merged = array_merge($rule->only(array_keys($this->rules(required: false))), $data);
        $this->assertBaseRuleInvariant($merged, ignoreRuleId: $rule->id);

        $rule->update($data);

        return response()->json(['data' => $rule->fresh()]);
    }

    /**
     * DELETE /api/crm/loyalty/rules/{rule}
     *
     * Soft in effect, not in schema: the row is deactivated, never deleted.
     * A rule already referenced by loyalty_transactions.rule_id must stay
     * readable — deleting it would turn a historical ledger line into one
     * pointing at nothing, the exact kind of unexplained record the ledger's
     * append-only design exists to avoid.
     */
    public function destroy(Request $request, LoyaltyRule $rule): JsonResponse
    {
        $this->assertNotTheOnlyBaseRule($rule);

        $rule->update(['is_active' => false]);

        return response()->json(['data' => $rule->fresh()]);
    }

    /**
     * GET /api/crm/loyalty/rules/{rule}/exclusions
     */
    public function exclusions(LoyaltyRule $rule): JsonResponse
    {
        $rows = $rule->exclusions()->with('customer:id,name,code')->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/crm/loyalty/rules/{rule}/exclusions
     */
    public function addExclusion(Request $request, LoyaltyRule $rule): JsonResponse
    {
        // Excluding a customer from the base rule does not "just remove the
        // discount" the way excluding them from a category rule does — the
        // base rule is the only source of the rate every other rule
        // multiplies. Removing it from a customer's candidate list zeroes
        // their earning, silently, on any item no other rule happens to
        // match. There is no legitimate business reason to want that.
        abort_if(
            $rule->isBaseRule(),
            422,
            'لا يمكن استثناء عميل من القاعدة الأساسية العامة — استثناؤه منها يوقف اكتسابه للنقاط كلياً وبصمت على أي صنف لا تطابقه قاعدة أخرى.'
        );

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ]);

        $exclusion = LoyaltyRuleExclusion::firstOrCreate([
            'rule_id' => $rule->id,
            'customer_id' => $data['customer_id'],
        ]);

        return response()->json(['data' => $exclusion->load('customer:id,name,code')], 201);
    }

    /**
     * DELETE /api/crm/loyalty/rules/{rule}/exclusions/{customer}
     */
    public function removeExclusion(LoyaltyRule $rule, int $customer): JsonResponse
    {
        $deleted = LoyaltyRuleExclusion::where('rule_id', $rule->id)
            ->where('customer_id', $customer)
            ->delete();

        abort_unless($deleted > 0, 404, 'لا يوجد استثناء لهذا العميل على هذه القاعدة.');

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'scope_type' => [$presence, Rule::in(self::SCOPE_TYPES)],
            'scope_id' => ['nullable', 'integer'],
            'points_per_amount' => ['nullable', 'numeric', 'min:0'],
            'per_amount' => ['nullable', 'numeric', 'gt:0'],
            'multiplier' => ['nullable', 'numeric', 'min:0'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'priority' => ['nullable', 'integer'],
            'is_campaign' => ['nullable', 'boolean'],
            'campaign_target' => ['nullable', 'numeric', 'min:0'],
            'campaign_target_metric' => ['nullable', Rule::in(self::CAMPAIGN_METRICS)],
            'group_cascade_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Guards LoyaltyRule::isBaseRuleData()'s invariant — exactly one active
     * row may match it — enforced here at write time rather than left for
     * LoyaltyEngine::baseRule() to discover mid-payment.
     */
    private function assertBaseRuleInvariant(array $data, ?int $ignoreRuleId = null): void
    {
        // isBaseRuleShape(), not isBaseRuleData(): a payload missing its rate
        // still needs to be caught by the abort_if() below and told so — if
        // the rate-presence check were folded into the shape check, a
        // rateless global-permanent row would silently look like "not a
        // base rule" and skip both this error and the uniqueness check.
        if (! LoyaltyRule::isBaseRuleShape($data)) {
            return;
        }

        abort_if(
            empty($data['points_per_amount']) || empty($data['per_amount']),
            422,
            'القاعدة العامة الدائمة (بلا حد أدنى وبلا تاريخ انتهاء) يجب أن تحمل points_per_amount وper_amount.'
        );

        // isBaseRule() here, not isBaseRuleShape() — the single definition
        // shared with LoyaltyEngine::baseRule() and the `is_base_rule` API
        // field, so "would this create a duplicate" always agrees with
        // "which row does the engine actually treat as the base rule."
        $conflicting = LoyaltyRule::query()
            ->where('is_active', true)
            ->when($ignoreRuleId, fn ($q) => $q->where('id', '!=', $ignoreRuleId))
            ->get()
            ->contains(fn (LoyaltyRule $r) => $r->isBaseRule());

        abort_if($conflicting, 422, 'توجد بالفعل قاعدة أساسية عامة دائمة نشطة — لا يجوز أن توجد أكثر من واحدة.');
    }

    /**
     * Refuses to deactivate the base rule when it is the only active one —
     * LoyaltyEngine throws with no active base rule at all, so the next paid
     * order would fail loudly (caught and logged by the listener, but every
     * order after this one would silently earn nothing). Blocking it here is
     * cheaper than discovering it from a support ticket.
     */
    private function assertNotTheOnlyBaseRule(LoyaltyRule $rule): void
    {
        if (! $rule->isBaseRule()) {
            return;
        }

        $otherActiveBaseRules = LoyaltyRule::query()
            ->where('id', '!=', $rule->id)
            ->where('is_active', true)
            ->get()
            ->contains(fn (LoyaltyRule $r) => $r->isBaseRule());

        abort_if(
            ! $otherActiveBaseRules,
            422,
            'لا يمكن تعطيل هذه القاعدة — إنها القاعدة الأساسية العامة الوحيدة النشطة. سيتوقف حساب النقاط على كل طلب بلا بديل.'
        );
    }
}
