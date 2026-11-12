<?php

namespace App\Services\Inventory;

use Carbon\CarbonInterface;

/**
 * سطر حركة مطلوب تسجيلها عبر StockLedger.
 *
 * الكمية والتكلفة بالوحدة المُدخلة ($unitId)؛ StockLedger يحوّلهما للوحدة الأساسية.
 * الكمية بإشارة: موجبة = وارد، سالبة = صادر. تكلفة الوحدة إلزامية للوارد، وتُترك فارغة للصادر
 * (يخرج بمتوسط التكلفة الحالي).
 */
final class StockMovementLine
{
    public function __construct(
        public readonly int $warehouseId,
        public readonly int $stockItemId,
        public readonly string $type,
        public readonly string|int|float $quantity,
        public readonly ?int $unitId = null,
        public readonly string|int|float|null $unitCost = null,
        public readonly ?CarbonInterface $occurredAt = null,
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $notes = null,
        /** للصادر: التوزيع اليدوي على الدفعات [{layer_id, quantity}] بالوحدة الأساسية (التحديد المعيّن)؛ null = الأقدم أولاً. */
        public readonly ?array $allocations = null,
        /** سطر المستند / الطلب / الجرد الذي تخصه الحركة (يُحفظ على التخصيصات والنقص المعلّق). */
        public readonly ?int $sourceLineId = null,
        /** للوارد: بيانات الدفعة (source_type, source_id, source_line_id, purchase_bill_id, supplier_id, received_at). */
        public readonly ?array $layer = null,
        /** لوارد التحويل: حركة الصادر التي تُنسخ دفعاتها (السعر وتاريخ الاستلام ومرجع الفاتورة الأصلية). */
        public readonly ?int $mirrorOf = null,
    ) {}
}
