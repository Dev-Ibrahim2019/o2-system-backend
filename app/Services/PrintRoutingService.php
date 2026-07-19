<?php

namespace App\Services;

use App\Models\Printer;
use App\Models\PrintRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * خدمة التوجيه الذكي للطباعة
 * ─────────────────────────────
 * الخوارزمية: عند استلام فاتورة/أمر تشغيل:
 * 1. Loop على كل صنف في الطلب
 * 2. فحص item_id مباشرة (أعلى أولوية)
 * 3. إذا لم يجد → فحص category_id
 * 4. إذا لم يجد → استخدام الطابعة الافتراضية
 * 5. تجميع الأصناف الموجهة لنفس الطابعة في مصفوفة
 * 6. إرسال كل مجموعة عبر TCP Socket
 */
class PrintRoutingService
{
    /**
     * معالجة طباعة فاتورة/أمر تشغيل
     */
    public function routeOrder(int $branchId, array $items, ?int $userId = null): array
    {
        $routes = $this->getActiveRoutes($branchId);
        $printerGroups = [];

        foreach ($items as $item) {
            $printerId = $this->resolvePrinterForItem($item, $routes, $userId, $branchId);

            if ($printerId === null) {
                Log::warning("No print route for item {$item['item_id']} in branch {$branchId}");
                continue;
            }

            if (!isset($printerGroups[$printerId])) {
                $printerGroups[$printerId] = [];
            }

            $printerGroups[$printerId][] = $item;
        }

        $result = [];
        foreach ($printerGroups as $printerId => $groupItems) {
            $printer = Printer::find($printerId);
            if ($printer && $printer->is_active) {
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
     * الأولوية: ITEM → CATEGORY → default
     */
    private function resolvePrinterForItem(
        array $item,
        Collection $routes,
        ?int $userId,
        int $branchId
    ): ?int {
        $itemId = $item['item_id'];
        $categoryId = $item['category_id'] ?? null;

        // المستوى 1: بحث دقيق عن الصنف
        $itemRoute = $routes->first(function ($route) use ($itemId, $userId) {
            return $route->item_id != null
                && $route->item_id == $itemId
                && $this->matchesUser($route, $userId);
        });

        if ($itemRoute) {
            return $itemRoute->printer_id;
        }

        // المستوى 2: بحث بالقسم
        if ($categoryId) {
            $categoryRoute = $routes->first(function ($route) use ($categoryId, $userId) {
                return $route->category_id != null
                    && $route->category_id == $categoryId
                    && $this->matchesUser($route, $userId);
            });

            if ($categoryRoute) {
                return $categoryRoute->printer_id;
            }
        }

        // المستوى 3: الطابعة الافتراضية
        $defaultPrinter = Printer::where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('type', 'KITCHEN')
            ->first();

        return $defaultPrinter?->id;
    }

    private function matchesUser(PrintRoute $route, ?int $userId): bool
    {
        if ($route->user_id === null) {
            return true;
        }
        return $userId !== null && $route->user_id == $userId;
    }

    private function getActiveRoutes(int $branchId): Collection
    {
        return PrintRoute::where('branch_id', $branchId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * إرسال أمر طباعة لطابعة SNBC عبر TCP Socket
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
