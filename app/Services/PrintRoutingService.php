<?php

namespace App\Services;

use App\Models\Printer;
use App\Models\PrintRoute;
use Illuminate\Support\Facades\Log;

/**
 * خدمة التوجيه الذكي للطباعة
 * ─────────────────────────────
 * الخوارزمية: عند استلام فاتورة/أمر تشغيل:
 * 1. جلب القوانين النشطة للفرع (مع مراعاة الجهاز المرسل)
 * 2. Loop على كل صنف في الطلب
 * 3. فحص item_id مباشرة (أعلى أولوية)
 * 4. إذا لم يجد → فحص category_id
 * 5. إذا لم يجد → استخدام الطابعة الافتراضية (KITCHEN)
 * 6. تجميع الأصناف الموجهة لنفس الطابعة في مصفوفة
 * 7. إرجاع المجموعات جاهزة للرندرة والطباعة
 */
class PrintRoutingService
{
    /**
     * توجيه أصناف الطلب إلى الطابعات المناسبة.
     *
     * الخوارزمية الجديدة (تعتمد على pivot tables الطابعة):
     * 1. Level 1: بحث في printer_item pivot (صنف محدد لطابعة)
     * 2. Level 2: بحث في printer_department pivot (قسم مرتبط بطابعة)
     * 3. Level 3: fallback → طابعة KITCHEN الافتراضية
     *
     * @param  int       $branchId   ID الفرع
     * @param  array     $items      أصناف الطلب — كل صنف: ['item_id', 'category_id', ...]
     * @param  ?int      $userId     ID المستخدم المرسل (غير مستخدم حالياً)
     * @param  ?string   $deviceType 'POS' | 'WAITER_APP' | null
     * @param  ?int      $deviceId   ID الجهاز المحدد
     * @return array     ['printer' => Printer, 'items' => array, 'count' => int][]
     */
    public function routeOrder(
        int $branchId,
        array $items,
        ?int $userId = null,
        ?string $deviceType = null,
        ?int $deviceId = null
    ): array {
        // جلب الطابعات النشطة للفرع مع العلاقات
        $printers = Printer::where('branch_id', $branchId)
            ->where('is_active', true)
            ->with(['departments', 'items'])
            ->get();

        // فلترة حسب نوع الجهاز (CASHIER = linked_pos_register_id)
        $cashierPrinters = $printers->where('type', 'CASHIER');
        $kitchenPrinters = $printers->whereIn('type', ['KITCHEN', 'BAR', 'OTHER']);

        $printerGroups = [];

        foreach ($items as $item) {
            $printerId = $this->resolvePrinterForItem(
                $item,
                $printers,
                $cashierPrinters,
                $kitchenPrinters,
                $userId,
                $branchId,
                $deviceType,
                $deviceId
            );

            if ($printerId === null) {
                Log::warning("No print route for item {$item['item_id']} in branch {$branchId}");
                continue;
            }

            $printerGroups[$printerId][] = $item;
        }

        $result = [];
        foreach ($printerGroups as $printerId => $groupItems) {
            $printer = $printers->firstWhere('id', $printerId);
            if ($printer) {
                $result[] = [
                    'printer' => $printer,
                    'items'   => $groupItems,
                    'count'   => count($groupItems),
                ];
            }
        }

        return $result;
    }

    /**
     * تحديد الطابعة المناسبة لصنف معين
     * الأولوية: printer_item → printer_department → default KITCHEN
     */
    private function resolvePrinterForItem(
        array $item,
        $allPrinters,
        $cashierPrinters,
        $kitchenPrinters,
        ?int $userId,
        int $branchId,
        ?string $deviceType,
        ?int $deviceId
    ): ?int {
        $itemId = $item['item_id'];
        $categoryId = $item['category_id'] ?? null;

        // المستوى 1: بحث في printer_item pivot (أعلى أولوية)
        foreach ($allPrinters as $printer) {
            if ($printer->items->contains('id', $itemId)) {
                // إذا كانت طابعة كاشير، تحقق من تطابق الجهاز
                if ($printer->type === 'CASHIER') {
                    if ($this->matchesDevice($printer, $deviceType, $deviceId)) {
                        return $printer->id;
                    }
                } else {
                    return $printer->id;
                }
            }
        }

        // المستوى 2: بحث في printer_department pivot
        if ($categoryId) {
            foreach ($allPrinters as $printer) {
                if ($printer->departments->contains('id', $categoryId)) {
                    if ($printer->type === 'CASHIER') {
                        if ($this->matchesDevice($printer, $deviceType, $deviceId)) {
                            return $printer->id;
                        }
                    } else {
                        return $printer->id;
                    }
                }
            }
        }

        // المستوى 3: الطابعة الافتراضية (KITCHEN)
        $defaultPrinter = $kitchenPrinters->first();
        return $defaultPrinter?->id;
    }

    /**
     * فحص تطابق الجهاز مع الطابعة
     */
    private function matchesDevice(Printer $printer, ?string $deviceType, ?int $deviceId): bool
    {
        // إذا لم يحدد جهاز → مطابقة عامة
        if ($deviceType === null || $deviceId === null) {
            return true;
        }

        // طابعة الكاشير مرتبطة بجهاز POS محدد
        if ($printer->type === 'CASHIER' && $printer->linked_pos_register_id !== null) {
            if ($deviceType === 'POS' && $printer->linked_pos_register_id == $deviceId) {
                return true;
            }
            return false;
        }

        return true;
    }

    /**
     * إرسال أمر طباعة لطابعة عبر TCP Socket (legacy text mode)
     */
    public function sendToPrinter(Printer $printer, array $items): array
    {
        $ip = $printer->ip_address;
        $port = (int) $printer->port;
        $content = $this->buildPrintContent($printer, $items);

        try {
            $fp = fsockopen($ip, $port, $errno, $errstr, 5);

            if (!$fp) {
                return [
                    'success' => false,
                    'printer' => $printer->name,
                    'message' => "فشل الاتصال: {$errstr} ({$errno})",
                ];
            }

            fwrite($fp, $content);
            fclose($fp);

            return [
                'success' => true,
                'printer' => $printer->name,
                'message' => 'تم الإرسال بنجاح',
                'items_count' => count($items),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'printer' => $printer->name,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function buildPrintContent(Printer $printer, array $items): string
    {
        $lines = [];
        $lines[] = str_repeat('=', 32);
        $lines[] = $printer->name;
        $lines[] = date('Y-m-d H:i:s');
        $lines[] = str_repeat('-', 32);

        foreach ($items as $item) {
            $name = $item['name'] ?? 'صنف #' . $item['item_id'];
            $qty = $item['quantity'] ?? 1;
            $lines[] = "{$qty}x {$name}";
            if (!empty($item['note'])) {
                $lines[] = "  ملاحظة: {$item['note']}";
            }
        }

        $lines[] = str_repeat('=', 32);
        $lines[] = "عدد الأصناف: " . count($items);
        $lines[] = '';

        return implode("\n", $lines);
    }
}
