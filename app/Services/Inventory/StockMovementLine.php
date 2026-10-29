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
        /** الدفعة: الصادر لا يتجاوز رصيدها في المخزن، والوارد يُضاف لرصيدها فيه. */
        public readonly ?int $batchId = null,
    ) {}
}
