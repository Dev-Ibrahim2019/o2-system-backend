<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\DiscountResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Discount;
use App\Models\DiscountExclusion;
use App\Models\DiscountTarget;
use App\Models\Employee;
use App\Models\Item;
use App\Models\Supplier;
use App\Services\Discount\DiscountEngineService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DiscountController extends ApiController
{
    private const TARGET_TYPES = [
        'customer', 'employee', 'supplier', 'department', 'item', 'category', 'brand', 'modifier', 'branch',
        'all_customers', 'all_employees', 'all_suppliers', 'all',
    ];

    public function __construct(private readonly DiscountEngineService $discountEngine) {}

    public function index(Request $request): JsonResponse
    {
        $query = Discount::with(['targets', 'exclusions', 'creator']);

        if ($request->status === 'active') {
            $query->active();
        } elseif ($request->status === 'expired') {
            $query->expired();
        }

        if ($request->discount_type) {
            $query->where('discount_type', $request->discount_type);
        }

        if ($request->from) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->to) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        if ($request->search) {
            $search = $request->search;
            $query->where(fn($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%"));
        }

        return $this->success(
            'Discount list',
            DiscountResource::collection($query->orderByDesc('created_at')->paginate($request->per_page ?? 20))
        );
    }

    public function show(Discount $discount): JsonResponse
    {
        $discount->load(['targets', 'exclusions', 'creator', 'usageLogs' => fn($q) => $q->latest()->limit(50)]);

        return $this->success('Discount details', new DiscountResource($discount));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $data = $this->validatedPayload($request);
            $this->validateTargetSets($data['targets'] ?? [], $data['exclusions'] ?? []);

            $discount = DB::transaction(function () use ($data, $request) {
                $discount = Discount::create($this->discountAttributes($data, $request->user()?->id));
                $this->syncTargets($discount, $data['targets'] ?? []);
                $this->syncExclusions($discount, $data['exclusions'] ?? []);

                return $discount;
            });

            return $this->success('Discount created successfully', new DiscountResource($discount->load(['targets', 'exclusions'])), 201);
        } catch (ValidationException $e) {
            return $this->error(collect($e->errors())->flatten()->first(), 422);
        } catch (\Throwable $e) {
            return $this->error('Failed to create discount: ' . $e->getMessage(), 500);
        }
    }

    public function update(Request $request, Discount $discount): JsonResponse
    {
        try {
            $data = $this->validatedPayload($request, $discount);
            $this->validateTargetSets($data['targets'] ?? [], $data['exclusions'] ?? []);

            DB::transaction(function () use ($discount, $data) {
                $discount->update($this->discountAttributes($data, $discount->created_by));

                if (array_key_exists('targets', $data)) {
                    $this->syncTargets($discount, $data['targets'] ?? []);
                }

                if (array_key_exists('exclusions', $data)) {
                    $this->syncExclusions($discount, $data['exclusions'] ?? []);
                }
            });

            return $this->success('Discount updated successfully', new DiscountResource($discount->fresh(['targets', 'exclusions'])));
        } catch (ValidationException $e) {
            return $this->error(collect($e->errors())->flatten()->first(), 422);
        } catch (\Throwable $e) {
            return $this->error('Failed to update discount: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(Discount $discount): JsonResponse
    {
        $discount->delete();

        return $this->success('Discount deleted successfully');
    }

    public function entities(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', 'employee');
        $search = trim((string) $request->query('search', ''));
        $perPage = min(50, max(5, (int) $request->query('per_page', 15)));

        $query = $this->entityQuery($type);
        if (! $query) {
            return $this->error("Entity type '{$type}' is not backed by a model yet.", 422);
        }

        if (mb_strlen($search) >= 2) {
            $this->applyEntitySearch($query, $type, $search);
        }

        $page = $query->paginate($perPage);

        return $this->success('Entity lookup results', [
            'data' => collect($page->items())->map(fn($entity) => $this->formatEntity($type, $entity))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function calculate(Request $request): JsonResponse
    {
        $data = $this->validatedCalculation($request);
        $bestDiscount = $this->discountEngine->getBestDiscount(...$this->engineArguments($data));

        if (! $bestDiscount) {
            return $this->success('No applicable discount', [
                'has_discount' => false,
                'original_price' => (float) $data['price'] * (int) ($data['quantity'] ?? 1),
                'discount_amount' => 0,
                'final_price' => (float) $data['price'] * (int) ($data['quantity'] ?? 1),
            ]);
        }

        return $this->success('Discount calculated', $this->formatEngineMatch($bestDiscount) + ['has_discount' => true]);
    }

    public function calculateCart(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'nullable|string',
            'items.*.department_id' => 'nullable|integer',
            'items.*.category_id' => 'nullable|integer',
            'items.*.brand_id' => 'nullable|integer',
            'items.*.modifier_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'employee_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        return $this->success('Cart discount result', $this->discountEngine->calculateCartDiscounts(
            $data['items'],
            $data['customer_id'] ?? null,
            $data['employee_id'] ?? null,
            $data['supplier_id'] ?? null,
            $data['branch_id'] ?? null
        ));
    }

    public function debug(Request $request): JsonResponse
    {
        $started = microtime(true);
        DB::enableQueryLog();

        $data = $this->validatedCalculation($request);
        $evaluation = $this->discountEngine->evaluateDiscounts(...$this->engineArguments($data));
        $matched = $evaluation['matched'][0] ?? null;

        return $this->success('Discount debug result', [
            'has_discount' => (bool) $matched,
            'matched_discount' => $matched ? $this->formatEngineMatch($matched) : null,
            'matched_rules' => array_map(fn($rule) => $this->formatEngineMatch($rule), $evaluation['matched']),
            'rejected_discounts' => $evaluation['rejected'],
            'excluded_discounts' => $evaluation['excluded'],
            'priority_resolution' => $matched ? 'Selected by priority, then highest discount amount.' : 'No matching rule after exclusions.',
            'execution_time_ms' => round((microtime(true) - $started) * 1000, 2),
            'sql_count' => count(DB::getQueryLog()),
        ]);
    }

    public function validateTarget(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'target_type' => 'required|string|in:' . implode(',', self::TARGET_TYPES),
            'target_id' => 'nullable|integer',
            'business_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $type = $data['target_type'];
        $id = $data['target_id'] ?? null;

        if (str_starts_with($type, 'all')) {
            return $this->success('Generic target is valid', [
                'found' => true,
                'target_type' => $type,
                'target_id' => null,
                'matched_discounts' => Discount::whereHas('targets', fn($q) => $q->where('target_type', $type))->count(),
            ]);
        }

        if (! $id && ! empty($data['business_code'])) {
            $id = $this->resolveBusinessCode($type, $data['business_code']);
        }

        $entity = $id ? $this->findEntity($type, (int) $id) : null;
        if (! $entity) {
            return $this->success('Target was not found', [
                'found' => false,
                'target_type' => $type,
                'target_id' => $id,
                'message' => 'Invalid or inactive/non-existing reference.',
            ]);
        }

        return $this->success('Target validation result', [
            'found' => true,
            'target_type' => $type,
            'target_id' => (int) $entity->id,
            'entity' => $this->formatEntity($type, $entity),
            'matched_discounts' => Discount::whereHas('targets', fn($q) => $q->where('target_type', $type)->where('target_id', $entity->id))->count(),
            'excluded_discounts' => Discount::whereHas('exclusions', fn($q) => $q->where('target_type', $type)->where('target_id', $entity->id))->count(),
            'expired_discounts' => Discount::expired()->whereHas('targets', fn($q) => $q->where('target_type', $type)->where('target_id', $entity->id))->count(),
            'message' => 'Target exists and can be safely referenced by database id internally.',
        ]);
    }

    public function dashboard(): JsonResponse
    {
        return $this->success('Discount dashboard', [
            'stats' => [
                'total_discounts' => Discount::count(),
                'active_discounts' => Discount::active()->count(),
                'expired_discounts' => Discount::expired()->count(),
                'percentage_discounts' => Discount::where('discount_type', 'percentage')->count(),
                'fixed_discounts' => Discount::where('discount_type', 'fixed_amount')->count(),
                'price_override_discounts' => Discount::where('discount_type', 'price_override')->count(),
                'total_usage' => \App\Models\DiscountUsageLog::count(),
                'total_discount_amount' => \App\Models\DiscountUsageLog::sum('discount_amount'),
            ],
            'recent_usage' => \App\Models\DiscountUsageLog::with(['discount', 'invoice'])->latest()->limit(10)->get(),
        ]);
    }

    private function validatedPayload(Request $request, ?Discount $discount = null): array
    {
        $id = $discount?->id;
        $rules = [
            'name' => ($discount ? 'sometimes' : 'required') . '|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'code' => ($discount ? 'sometimes' : 'required') . "|string|max:50|unique:discounts,code,{$id}",
            'description' => 'nullable|string',
            'discount_type' => ($discount ? 'sometimes' : 'required') . '|in:percentage,fixed_amount,price_override,buy_x_get_y',
            'apply_strategy' => 'nullable|in:per_quantity,per_line,per_invoice,once',
            'value' => ($discount ? 'sometimes' : 'required') . '|numeric|min:0',
            'priority' => 'nullable|integer|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'targets' => 'nullable|array',
            'targets.*.target_type' => 'required|string|in:' . implode(',', self::TARGET_TYPES),
            'targets.*.target_id' => 'nullable|integer',
            'targets.*.business_code' => 'nullable|string',
            'targets.*.target_business_code' => 'nullable|string',
            'exclusions' => 'nullable|array',
            'exclusions.*.target_type' => 'required|string|in:' . implode(',', self::TARGET_TYPES),
            'exclusions.*.target_id' => 'nullable|integer',
            'exclusions.*.business_code' => 'nullable|string',
            'exclusions.*.target_business_code' => 'nullable|string',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();
        $discountType = $data['discount_type'] ?? $discount?->discount_type;
        $value = $data['value'] ?? $discount?->value;

        if ($discountType === 'percentage' && $value > 100) {
            throw ValidationException::withMessages(['value' => 'Discount percentage cannot exceed 100%.']);
        }

        foreach (['targets', 'exclusions'] as $set) {
            if (! empty($data[$set])) {
                foreach ($data[$set] as $index => $target) {
                    $data[$set][$index]['target_id'] = $this->resolveTargetId($target);
                }
            }
        }

        return $data;
    }

    private function discountAttributes(array $data, ?int $createdBy): array
    {
        $attributes = [];
        foreach ([
            'name',
            'name_ar',
            'code',
            'description',
            'discount_type',
            'apply_strategy',
            'value',
            'priority',
            'start_date',
            'end_date',
            'is_active',
            'max_discount_amount',
            'min_order_amount',
            'usage_limit',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }

        $attributes['apply_strategy'] ??= 'per_quantity';
        $attributes['priority'] ??= 0;
        $attributes['is_active'] ??= true;
        $attributes['created_by'] = $createdBy;

        return $attributes;
    }

    private function syncTargets(Discount $discount, array $targets): void
    {
        $discount->targets()->delete();
        foreach ($targets as $target) {
            DiscountTarget::create([
                'discount_id' => $discount->id,
                'target_type' => $target['target_type'],
                'target_id' => $target['target_id'] ?? null,
            ]);
        }
    }

    private function syncExclusions(Discount $discount, array $exclusions): void
    {
        $discount->exclusions()->delete();
        foreach ($exclusions as $target) {
            DiscountExclusion::create([
                'discount_id' => $discount->id,
                'target_type' => $target['target_type'],
                'target_id' => $target['target_id'] ?? null,
            ]);
        }
    }

    private function validateTargetSets(array $targets, array $exclusions): void
    {
        $seenTargets = [];
        foreach ($targets as $target) {
            $key = $target['target_type'] . ':' . ($target['target_id'] ?? 'all');
            if (isset($seenTargets[$key])) {
                throw ValidationException::withMessages(['targets' => "Duplicate target {$key}."]);
            }
            $seenTargets[$key] = true;
        }

        $seenExclusions = [];
        foreach ($exclusions as $target) {
            $key = $target['target_type'] . ':' . ($target['target_id'] ?? 'all');
            if (isset($seenExclusions[$key])) {
                throw ValidationException::withMessages(['exclusions' => "Duplicate exclusion {$key}."]);
            }
            $seenExclusions[$key] = true;
        }
    }

    private function resolveTargetId(array $target): ?int
    {
        $type = $target['target_type'];
        if (str_starts_with($type, 'all') || $type === 'all') {
            return null;
        }

        $code = $target['business_code'] ?? $target['target_business_code'] ?? null;
        if ($code) {
            return $this->resolveBusinessCode($type, $code);
        }

        if (! empty($target['target_id'])) {
            return (int) $target['target_id'];
        }

        throw ValidationException::withMessages([$type => "A business code or id is required for {$type}."]);
    }

    private function resolveBusinessCode(string $type, string $code): int
    {
        $query = $this->entityQuery($type);
        if (! $query) {
            throw ValidationException::withMessages([$type => "Business code lookup is not available for {$type}."]);
        }

        $column = $type === 'employee' ? 'employeeId' : 'code';
        $id = $query->where($column, $code)->value('id');

        if (! $id) {
            throw ValidationException::withMessages([$type => "Business code {$code} does not exist for {$type}."]);
        }

        return (int) $id;
    }

    private function entityQuery(string $type): ?Builder
    {
        return match ($type) {
            'employee' => Employee::withoutGlobalScopes()->with(['department', 'branch']),
            'customer' => Customer::with('branch'),
            'supplier' => Supplier::with('branch'),
            'department' => Department::query(),
            'item' => Item::query()->with('department'),
            'branch' => Branch::query(),
            default => null,
        };
    }

    private function applyEntitySearch(Builder $query, string $type, string $search): void
    {
        $query->where(function ($q) use ($type, $search) {
            if ($type === 'employee') {
                $q->where('employeeId', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            } elseif ($type === 'item') {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%");
            } elseif ($type === 'department') {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('nameAr', 'like', "%{$search}%");
            } else {
                $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
                if (in_array($type, ['customer', 'supplier', 'item'], true)) {
                    $q->orWhere('name_en', 'like', "%{$search}%");
                }
            }
        });
    }

    private function findEntity(string $type, int $id): ?object
    {
        return $this->entityQuery($type)?->find($id);
    }

    private function formatEntity(string $type, object $entity): array
    {
        $businessCode = $type === 'employee' ? ($entity->employeeId ?? null) : ($entity->code ?? null);

        return [
            'id' => $entity->id,
            'type' => $type,
            'business_code' => $businessCode,
            'employee_number' => $type === 'employee' ? $businessCode : null,
            'customer_code' => $type === 'customer' ? $businessCode : null,
            'supplier_code' => $type === 'supplier' ? $businessCode : null,
            'name' => $entity->name ?? $entity->name_en ?? '',
            'name_ar' => $entity->name_ar ?? $entity->nameAr ?? null,
            'department' => $entity->department->name ?? $entity->department->nameAr ?? null,
            'department_id' => $entity->department_id ?? null,
            'status' => $entity->status ?? ($entity->is_active ?? null),
            'branch' => $entity->branch->name ?? null,
            'branch_id' => $entity->branch_id ?? null,
            'price' => $entity->price ?? null,
        ];
    }

    private function validatedCalculation(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'price' => 'required|numeric|min:0',
            'quantity' => 'nullable|integer|min:1',
            'customer_id' => 'nullable|integer',
            'employee_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'department_id' => 'nullable|integer',
            'item_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
            'modifier_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function engineArguments(array $data): array
    {
        return [
            (float) $data['price'],
            (int) ($data['quantity'] ?? 1),
            $data['customer_id'] ?? null,
            $data['employee_id'] ?? null,
            $data['supplier_id'] ?? null,
            $data['department_id'] ?? null,
            $data['item_id'] ?? null,
            $data['branch_id'] ?? null,
            $data['category_id'] ?? null,
            $data['brand_id'] ?? null,
            $data['modifier_id'] ?? null,
        ];
    }

    private function formatEngineMatch(array $match): array
    {
        /** @var Discount $discount */
        $discount = $match['discount'];

        return [
            'id' => $discount->id,
            'name' => $discount->name,
            'code' => $discount->code,
            'discount_type' => $discount->discount_type,
            'value' => (float) $discount->value,
            'priority' => $discount->priority,
            'apply_strategy' => $match['apply_strategy'] ?? $discount->apply_strategy ?? 'per_quantity',
            'discount' => new DiscountResource($discount),
            'original_price' => $match['original_price'],
            'discount_amount' => $match['discount_amount'],
            'final_price' => $match['final_price'],
            'discount_percent' => $match['discount_percent'],
            'reason' => $match['reason'] ?? null,
            'matched_targets' => $discount->targets->values(),
        ];
    }
}
