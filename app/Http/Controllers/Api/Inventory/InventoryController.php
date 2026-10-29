<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryRuleException;
use App\Services\Inventory\WarehouseAccess;
use App\Traits\ApiResponses;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * أساس متحكّمات وحدة المخازن: رسائل تحقق عربية (لغة التطبيق الافتراضية
 * إنجليزية ولا توجد ملفات ترجمة)، وتحويل مخالفات قواعد العمل إلى 422.
 */
abstract class InventoryController extends Controller
{
    use ApiResponses;

    private const MESSAGES = [
        'required' => 'حقل :attribute مطلوب.',
        'required_with' => 'حقل :attribute مطلوب.',
        'string' => 'حقل :attribute يجب أن يكون نصاً.',
        'max' => 'حقل :attribute طويل جداً.',
        'integer' => 'حقل :attribute يجب أن يكون رقماً صحيحاً.',
        'numeric' => 'حقل :attribute يجب أن يكون رقماً.',
        'boolean' => 'قيمة :attribute غير صالحة.',
        'array' => 'قيمة :attribute غير صالحة.',
        'in' => 'قيمة :attribute غير مسموحة.',
        'exists' => ':attribute غير موجود.',
        'unique' => ':attribute مستخدم مسبقاً.',
        'gt' => 'حقل :attribute يجب أن يكون أكبر من صفر.',
        'min' => 'قيمة :attribute أقل من المسموح.',
        'date_format' => 'صيغة :attribute غير صحيحة.',
        'after_or_equal' => ':attribute يجب ألا يسبق تاريخ البداية.',
        'distinct' => ':attribute مكرّر.',
        'present' => 'حقل :attribute مطلوب.',
        'prohibited' => 'لا يمكن تغيير :attribute.',
    ];

    private const ATTRIBUTES = [
        'name' => 'الاسم',
        'symbol' => 'الرمز',
        'code' => 'الكود',
        'type' => 'النوع',
        'item_id' => 'صنف البيع',
        'base_unit_id' => 'الوحدة الأساسية',
        'barcode' => 'الباركود',
        'supplier_code' => 'كود المورّد',
        'safety_stock' => 'مخزون الأمان',
        'units' => 'الوحدات البديلة',
        'units.*.unit_id' => 'الوحدة',
        'units.*.factor' => 'معامل التحويل',
        'category_ids' => 'التصنيفات',
        'category_ids.*' => 'التصنيف',
        'parent_id' => 'التصنيف الأب',
        'branch_id' => 'الفرع',
        'user_ids' => 'المستخدمون',
        'user_ids.*' => 'المستخدم',
        'warehouse_id' => 'المخزن',
        'stock_item_id' => 'الصنف',
        'lines' => 'الأصناف',
        'lines.*.stock_item_id' => 'الصنف',
        'lines.*.quantity' => 'الكمية',
        'lines.*.unit_id' => 'الوحدة',
        'lines.*.unit_cost' => 'تكلفة الوحدة',
        'reason' => 'سبب العكس',
        'from' => 'من يوم',
        'to' => 'إلى يوم',
        'allow_negative_stock' => 'السماح بالرصيد السالب',
        'name_en' => 'الاسم بالإنجليزية',
        'purchase_unit_id' => 'وحدة الشراء',
        'default_warehouse_id' => 'المخزن الافتراضي',
        'to_warehouse_id' => 'المخزن الوجهة',
        'supplier_id' => 'المورّد',
        'supplier_invoice_no' => 'رقم فاتورة المورّد',
        'document_date' => 'تاريخ المستند',
        'notes' => 'الملاحظات',
        'lines.*.waste_reason' => 'سبب الهالك',
        'lines.*.notes' => 'ملاحظات السطر',
        'links' => 'الموردون',
        'links.*.supplier_id' => 'المورّد',
        'links.*.unit_id' => 'الوحدة',
        'links.*.price' => 'السعر',
        'links.*.supplier_item_code' => 'كود الصنف لدى المورّد',
        'department_id' => 'القسم',
        'effective_from' => 'تاريخ السريان',
        'yield_quantity' => 'كمية الناتج',
        'category_id' => 'التصنيف',
        'lines.*.entered_quantity' => 'الكمية المعدودة',
        'variance_alert_percent' => 'حد تنبيه الفرق',
    ];

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validateAr(Request $request, array $rules): array
    {
        return $request->validate($rules, self::MESSAGES, self::ATTRIBUTES);
    }

    /** ينفّذ العملية ويحوّل مخالفة قاعدة عمل إلى رد 422 برسالتها العربية. */
    protected function attempt(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (InventoryRuleException $e) {
            return $this->error($e->getMessage(), 422, $e->errors ?: null);
        }
    }

    /**
     * القراءة تكفيها المشاهدة؛ والكتابة (أي طلب غير GET) تحتاج مستوى "مشاهدة وتعديل" على المخزن.
     * هكذا تطبّق كل متحكّمات المخزون صلاحية القسم في الفرع (لا وصول / مشاهدة / تعديل) بلا تعديل كل منها.
     */
    protected function denyUnlessCanAccess(Request $request, Warehouse $warehouse): ?JsonResponse
    {
        $access = app(WarehouseAccess::class);
        if (! $access->canAccess($request->user(), $warehouse)) {
            return $this->error('لا تملك صلاحية الوصول لهذا المخزن.', 403);
        }
        if (! $request->isMethod('GET') && ! $access->canEdit($request->user(), $warehouse)) {
            return $this->error('صلاحيتك على هذا المخزن مشاهدة فقط.', 403);
        }

        return null;
    }
}
