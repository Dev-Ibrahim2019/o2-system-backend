<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\DiscountResource;
use App\Models\Discount;
use App\Models\DiscountTarget;
use App\Services\Discount\DiscountEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DiscountController extends ApiController
{
    protected DiscountEngineService $discountEngine;

    public function __construct(DiscountEngineService $discountEngine)
    {
        $this->discountEngine = $discountEngine;
    }

    /**
     * عرض جميع الخصومات (مع فلاتر)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Discount::with(['targets', 'creator']);

        // فلتر حسب الحالة
        if ($request->status === 'active') {
            $query->active();
        } elseif ($request->status === 'expired') {
            $query->expired();
        }

        // فلتر حسب النوع
        if ($request->discount_type) {
            $query->where('discount_type', $request->discount_type);
        }

        // فلتر حسب التاريخ
        if ($request->from) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        // بحث
        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('name_ar', 'like', "%{$search}%");
            });
        }

        $discounts = $query->orderByDesc('created_at')->paginate($request->per_page ?? 20);

        return $this->success('قائمة الخصومات', DiscountResource::collection($discounts));
    }

    /**
     * عرض خصم محدد
     */
    public function show(Discount $discount): JsonResponse
    {
        $discount->load(['targets', 'creator', 'usageLogs' => function ($q) {
            $q->latest()->limit(50);
        }]);

        return $this->success('تفاصيل الخصم', new DiscountResource($discount));
    }

    /**
     * إنشاء خصم جديد
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'code' => 'required|string|max:50|unique:discounts,code',
            'description' => 'nullable|string',
            'discount_type' => 'required|in:percentage,fixed_amount,price_override,buy_x_get_y',
            'value' => 'required|numeric|min:0',
            'priority' => 'nullable|integer|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',

            // المستهدفون
            'targets' => 'nullable|array',
            'targets.*.target_type' => 'required|string|in:customer,employee,supplier,department,item,all_customers,all_employees,all_suppliers,all',
            'targets.*.target_id' => 'nullable|integer|required_if:target_type,customer,employee,supplier,department,item',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        // التحقق من أن الخصم لا يتجاوز 100%
        if ($request->discount_type === 'percentage' && $request->value > 100) {
            return $this->error('نسبة الخصم لا يمكن أن تتجاوز 100%', 422);
        }

        $data = $validator->validated();

        DB::beginTransaction();
        try {
            $discount = Discount::create([
                'name' => $data['name'],
                'name_ar' => $data['name_ar'] ?? null,
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'discount_type' => $data['discount_type'],
                'value' => $data['value'],
                'priority' => $data['priority'] ?? 0,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'max_discount_amount' => $data['max_discount_amount'] ?? null,
                'min_order_amount' => $data['min_order_amount'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // إضافة المستهدفين
            if (!empty($data['targets'])) {
                foreach ($data['targets'] as $target) {
                    DiscountTarget::create([
                        'discount_id' => $discount->id,
                        'target_type' => $target['target_type'],
                        'target_id' => $target['target_id'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $discount->load('targets');

            return $this->success('تم إنشاء الخصم بنجاح', new DiscountResource($discount), 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل إنشاء الخصم: ' . $e->getMessage(), 500);
        }
    }

    /**
     * تحديث خصم
     */
    public function update(Request $request, Discount $discount): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'name_ar' => 'nullable|string|max:255',
            'code' => 'sometimes|string|max:50|unique:discounts,code,' . $discount->id,
            'description' => 'nullable|string',
            'discount_type' => 'sometimes|in:percentage,fixed_amount,price_override,buy_x_get_y',
            'value' => 'sometimes|numeric|min:0',
            'priority' => 'nullable|integer|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',

            // المستهدفون — إذا أرسلنا targets، يتم استبدال الكل
            'targets' => 'nullable|array',
            'targets.*.target_type' => 'required|string|in:customer,employee,supplier,department,item,all_customers,all_employees,all_suppliers,all',
            'targets.*.target_id' => 'nullable|integer|required_if:target_type,customer,employee,supplier,department,item',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        // التحقق من نسبة الخصم
        $discountType = $data['discount_type'] ?? $discount->discount_type;
        $value = $data['value'] ?? $discount->value;
        if ($discountType === 'percentage' && $value > 100) {
            return $this->error('نسبة الخصم لا يمكن أن تتجاوز 100%', 422);
        }

        DB::beginTransaction();
        try {
            $discount->update($data);

            // تحديث المستهدفين إذا أرسلنا targets
            if (isset($data['targets'])) {
                $discount->targets()->delete();
                foreach ($data['targets'] as $target) {
                    DiscountTarget::create([
                        'discount_id' => $discount->id,
                        'target_type' => $target['target_type'],
                        'target_id' => $target['target_id'] ?? null,
                    ]);
                }
            }

            DB::commit();

            $discount->load('targets');

            return $this->success('تم تحديث الخصم بنجاح', new DiscountResource($discount));
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل تحديث الخصم: ' . $e->getMessage(), 500);
        }
    }

    /**
     * حذف خصم
     */
    public function destroy(Discount $discount): JsonResponse
    {
        $discount->delete();

        return $this->success('تم حذف الخصم بنجاح');
    }

    /**
     * حساب الخصم لعنصر في سياق معين
     * (يستخدمها Frontend مباشرة)
     */
    public function calculate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'price' => 'required|numeric|min:0',
            'quantity' => 'nullable|integer|min:1',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'item_id' => 'nullable|integer|exists:items,id',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        $bestDiscount = $this->discountEngine->getBestDiscount(
            (float) $data['price'],
            (int) ($data['quantity'] ?? 1),
            $data['customer_id'] ?? null,
            $data['employee_id'] ?? null,
            $data['supplier_id'] ?? null,
            $data['department_id'] ?? null,
            $data['item_id'] ?? null
        );

        if (!$bestDiscount) {
            return $this->success('لا يوجد خصم مطبق', [
                'has_discount' => false,
                'original_price' => (float) $data['price'],
                'discount_amount' => 0,
                'final_price' => (float) $data['price'],
            ]);
        }

        return $this->success('تم حساب الخصم', [
            'has_discount' => true,
            'discount' => new DiscountResource($bestDiscount['discount']),
            'original_price' => $bestDiscount['original_price'],
            'discount_amount' => $bestDiscount['discount_amount'],
            'final_price' => $bestDiscount['final_price'],
            'discount_percent' => $bestDiscount['discount_percent'],
        ]);
    }

    /**
     * حساب خصومات السلة بالكامل
     */
    public function calculateCart(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'nullable|integer|min:1',
            'items.*.item_id' => 'nullable|integer',
            'items.*.item_name' => 'nullable|string',
            'items.*.department_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            'employee_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $data = $validator->validated();

        $result = $this->discountEngine->calculateCartDiscounts(
            $data['items'],
            $data['customer_id'] ?? null,
            $data['employee_id'] ?? null,
            $data['supplier_id'] ?? null
        );

        return $this->success('نتيجة حساب الخصومات', $result);
    }

    /**
     * إحصائيات لوحة الخصومات
     */
    public function dashboard(): JsonResponse
    {
        $stats = [
            'total_discounts' => Discount::count(),
            'active_discounts' => Discount::active()->count(),
            'expired_discounts' => Discount::expired()->count(),
            'percentage_discounts' => Discount::where('discount_type', 'percentage')->count(),
            'fixed_discounts' => Discount::where('discount_type', 'fixed_amount')->count(),
            'price_override_discounts' => Discount::where('discount_type', 'price_override')->count(),
            'total_usage' => \App\Models\DiscountUsageLog::count(),
            'total_discount_amount' => \App\Models\DiscountUsageLog::sum('discount_amount'),
        ];

        // آخر 10 استخدامات
        $recentUsage = \App\Models\DiscountUsageLog::with(['discount', 'invoice'])
            ->latest()
            ->limit(10)
            ->get();

        return $this->success('إحصائيات الخصومات', [
            'stats' => $stats,
            'recent_usage' => $recentUsage,
        ]);
    }
}
