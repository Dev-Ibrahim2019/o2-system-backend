<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Services\Printing\PrinterService;
use App\Services\Printing\Renderers\ArabicReceiptRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ─────────────────────────────────────────────────────────────
 *  DirectPrintRoutingService — خدمة الطباعة الفورية والفرز الذكي
 * ─────────────────────────────────────────────────────────────
 *  خدمة معزولة تماماً عن مسارات الطباعة الأساسية.
 *  تُستدعى فقط عند النقر على زر "تنفيذ وطباعة" للطلب الفوري.
 *
 *  القواعد:
 *  - طابعة القسم: تطبع تيكيت واحد يحتوي على كل الأصناف المخصصة لها
 *    (سواء من قسمها أو أصناف مخصصة بشكل استثنائي)
 *  - طابعة الكاشير: تطبع تيكيت مقسم — تيكيت واحد لكل مجموعة أقسام
 *    (بدون فاتورة كاملة)
 */
class DirectPrintRoutingService
{
    private PrinterService $printerService;
    private ArabicReceiptRenderer $receiptRenderer;

    public function __construct(
        PrinterService $printerService,
        ArabicReceiptRenderer $receiptRenderer
    ) {
        $this->printerService = $printerService;
        $this->receiptRenderer = $receiptRenderer;
    }

    /**
     * التنفيذ الرئيسي — معالجة الطلب الفوري وطباعته.
     */
    public function execute(int $orderId, int $cashierDeviceId): array
    {
        // ── 1. جلب الطلب ──
        $order = Order::with(['items.department', 'branch'])->find($orderId);

        if (!$order) {
            return [
                'success' => false,
                'message' => 'الطلب غير موجود',
                'print_jobs' => [],
                'printed_items_count' => 0,
            ];
        }

        // ── 2. تصفية الأصناف (منع التكرار) ──
        $pendingItems = $order->items()
            ->where('is_printed_direct', false)
            ->where('status', '!=', 'cancelled')
            ->get();

        if ($pendingItems->isEmpty()) {
            return [
                'success' => true,
                'message' => 'جميع الأصناف طُبعت مسبقاً',
                'print_jobs' => [],
                'printed_items_count' => 0,
            ];
        }

        // ── 3. جلب طابعات الفرع ──
        $printers = Printer::where('branch_id', $order->branch_id)
            ->where('is_active', true)
            ->with(['departments', 'items'])
            ->get();

        // ── 4. تعيين كل صنف لطابعته المستهدفة ──
        $itemPrinterMap = $this->mapItemsToPrinters($pendingItems, $printers);

        // ── 5. توليد أوامر الطباعة ──
        $printJobs = [];

        // 5أ. طابعات الأقسام — تيكيت واحد لكل طابعة يحتوي كل أصنافها
        $departmentJobs = $this->buildDepartmentPrintJobs(
            $order, $itemPrinterMap, $printers
        );
        $printJobs = array_merge($printJobs, $departmentJobs);

        // 5ب. طابعة الكاشير — تيكيت مقسم لكل مجموعة أقسام
        $cashierJob = $this->buildCashierPrintJob(
            $order, $itemPrinterMap, $cashierDeviceId
        );
        if ($cashierJob) {
            $printJobs[] = $cashierJob;
        }

        // ── 6. تحديث حالة الأصناف ──
        $printedItemIds = $pendingItems->pluck('id')->toArray();
        if (!empty($printedItemIds)) {
            OrderItem::whereIn('id', $printedItemIds)
                ->update(['is_printed_direct' => true]);
        }

        // ── 7. تنفيذ الطباعة فعلياً ──
        $results = $this->executePrintJobs($printJobs);

        return [
            'success' => true,
            'message' => 'تمت معالجة الطباعة الفورية',
            'print_jobs' => $results,
            'printed_items_count' => count($printedItemIds),
        ];
    }

    /**
     * تعيين كل صنف لطابعته المستهدفة.
     *
     * القواعد (بالترتيب):
     * 1. إذا كان الصنف مخصصاً لطابعة معينة عبر printer_item pivot
     *    → تذهب لطابعة الصنف المخصصة
     * 2. وإلاً → تذهب لطابعة القسم التابع له الصنف
     *    (أي طابعة في نفس النوع linkedة للأقسام)
     *
     * @return array<int, array{item: OrderItem, printer: Printer, department_group: string}>
     */
    private function mapItemsToPrinters(
        Collection $pendingItems,
        Collection $printers
    ): array {
        $map = [];

        // بناء فهرس: item_id → [printer_ids] من pivot printer_item
        $itemToPrinters = [];
        foreach ($printers as $printer) {
            foreach ($printer->items as $printerItem) {
                $itemToPrinters[$printerItem->id][] = $printer->id;
            }
        }

        // بناء فهرس: department_id → [printers] من pivot printer_department
        $deptToPrinters = [];
        foreach ($printers as $printer) {
            foreach ($printer->departments as $dept) {
                $deptToPrinters[$dept->id][] = $printer->id;
            }
        }

        foreach ($pendingItems as $item) {
            $targetPrinter = null;

            // القاعدة 1: الصنف مخصص لطابعة معينة (printer_item pivot)
            if (isset($itemToPrinters[$item->item_id])) {
                // أول طابعة نشطة من القائمة
                foreach ($itemToPrinters[$item->item_id] as $pid) {
                    $p = $printers->firstWhere('id', $pid);
                    if ($p && $p->print_on_direct) {
                        $targetPrinter = $p;
                        break;
                    }
                }
            }

            // القاعدة 2: طابعة القسم
            if (!$targetPrinter && isset($deptToPrinters[$item->department_id])) {
                foreach ($deptToPrinters[$item->department_id] as $pid) {
                    $p = $printers->firstWhere('id', $pid);
                    if ($p && $p->print_on_direct) {
                        $targetPrinter = $p;
                        break;
                    }
                }
            }

            if ($targetPrinter) {
                $map[] = [
                    'item' => $item,
                    'printer' => $targetPrinter,
                    'department_group' => $item->department->name ?? 'قسم #' . $item->department_id,
                ];
            }
        }

        return $map;
    }

    /**
     * توليد أوامر الطباعة لطابعات الأقسام (المطابخ)
     *
     * تجمع الأصناف حسب الطابعة وتولّد تيكيت واحد لكل طابعة
     * يحتوي كل الأصناف المخصصة لها.
     */
    private function buildDepartmentPrintJobs(
        Order $order,
        array $itemPrinterMap,
        Collection $allPrinters
    ): array {
        // تجميع الأصناف حسب معرّف الطابعة
        $printerItems = [];
        foreach ($itemPrinterMap as $entry) {
            $printerId = $entry['printer']->id;
            if (!isset($printerItems[$printerId])) {
                $printerItems[$printerId] = [];
            }
            $printerItems[$printerId][] = $this->formatItem($entry['item']);
        }

        $jobs = [];
        foreach ($printerItems as $printerId => $items) {
            $printer = $allPrinters->firstWhere('id', $printerId);
            if (!$printer || $printer->type === 'CASHIER') {
                continue;
            }

            $jobs[] = [
                'printer' => $printer,
                'type' => 'DEPARTMENT',
                'items' => $items,
                'order' => $order,
            ];
        }

        return $jobs;
    }

    /**
     * توليد أمر طباعة لطابعة الكاشير (المجمّعة المقسمة)
     *
     * تطبع تيكيت مقسم — تيكيت واحد لكل مجموعة أقسام
     * (بدون فاتورة كاملة).
     *
     * مثال: شاورما + كولا + بيتزا
     * - شاورما وكولا يذهبان لطابعة الشاورما → مجموعة واحدة
     * - بيتزا يذهب لطابعة الايطالي → مجموعة ثانية
     * - طابعة الكاشير: تيكيت 1 (شورما + كولا) + تيكيت 2 (بيتزا)
     */
    private function buildCashierPrintJob(
        Order $order,
        array $itemPrinterMap,
        int $cashierDeviceId
    ): ?array {
        $cashierPrinter = Printer::where('branch_id', $order->branch_id)
            ->where('type', 'CASHIER')
            ->where('linked_pos_register_id', $cashierDeviceId)
            ->where('is_active', true)
            ->first();

        if (!$cashierPrinter) {
            Log::info("DirectPrint: No active CASHIER printer for device #{$cashierDeviceId}");
            return null;
        }

        // تجميع الأصناف حسب معرّف الطابعة (للحصول على مجموعات الأقسام)
        $groupedByPrinter = [];
        foreach ($itemPrinterMap as $entry) {
            $printerId = $entry['printer']->id;
            if (!isset($groupedByPrinter[$printerId])) {
                $groupedByPrinter[$printerId] = [
                    'printer_name' => $entry['printer']->name,
                    'items' => [],
                ];
            }
            $groupedByPrinter[$printerId]['items'][] = $this->formatItem($entry['item']);
        }

        // تحويل إلى مصفوفة sections للكاشير
        $sectionReceipts = [];
        foreach ($groupedByPrinter as $group) {
            $sectionReceipts[] = [
                'section_name' => $group['printer_name'],
                'items' => $group['items'],
            ];
        }

        return [
            'printer' => $cashierPrinter,
            'type' => 'CASHIER',
            'sections' => $sectionReceipts,
            'order' => $order,
        ];
    }

    /**
     * تنسيق صنف للطباعة
     */
    private function formatItem(OrderItem $item): array
    {
        return [
            'item_id'      => $item->item_id,
            'item_name_ar' => $item->item_name_ar ?: $item->item_name,
            'item_name'    => $item->item_name,
            'quantity'     => (float) $item->quantity,
            'price'        => (float) $item->price,
            'total'        => (float) $item->total,
            'notes'        => $item->notes,
            'department_id'=> $item->department_id,
        ];
    }

    /**
     * تنفيذ أوامر الطباعة فعلياً
     */
    private function executePrintJobs(array $printJobs): array
    {
        $results = [];

        foreach ($printJobs as $job) {
            $printer = $job['printer'];

            try {
                if ($job['type'] === 'CASHIER') {
                    $result = $this->printCashierSections($job);
                } else {
                    $result = $this->printDepartmentKot($job);
                }

                $results[] = $result;
            } catch (\Throwable $e) {
                Log::error("DirectPrint failed for printer [{$printer->name}]", [
                    'printer_id' => $printer->id,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'success' => false,
                    'printer_id' => $printer->id,
                    'printer_name' => $printer->name,
                    'printer_type' => $printer->type,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * طباعة مقسمة لطابعة الكاشير — تيكيت department-ticket لكل مجموعة
     *
     * تستخدم قالب department-ticket.blade.php (تيكيتات صغيرة مضغوطة)
     * بدل kot.blade.php أو invoice.blade.php.
     */
    private function printCashierSections(array $job): array
    {
        $printer = $job['printer'];
        $order = $job['order'];
        $results = [];

        foreach ($job['sections'] as $section) {
            $imagePath = $this->receiptRenderer->renderDepartmentTicket(
                $order,
                $section['section_name'],
                $section['items']
            );

            try {
                $printResult = $this->printerService->printReceiptImage($printer, $imagePath);
                $results[] = array_merge($printResult, [
                    'printer_id' => $printer->id,
                    'printer_name' => $printer->name,
                    'section' => $section['section_name'],
                    'items_count' => count($section['items']),
                ]);
            } finally {
                $this->receiptRenderer->cleanup($imagePath);
            }
        }

        return [
            'success' => !empty($results) && !collect($results)->contains('success', false),
            'printer_id' => $printer->id,
            'printer_name' => $printer->name,
            'printer_type' => 'CASHIER',
            'sections_count' => count($job['sections']),
            'details' => $results,
        ];
    }

    /**
     * طباعة KOT لطابعة القسم — تيكيت واحد يحتوي كل الأصناف
     */
    private function printDepartmentKot(array $job): array
    {
        $printer = $job['printer'];
        $order = $job['order'];
        $items = $job['items'];

        $imagePath = $this->receiptRenderer->renderKot(
            $order,
            $printer->name,
            $items
        );

        try {
            $result = $this->printerService->printReceiptImage($printer, $imagePath);

            return array_merge($result, [
                'printer_id' => $printer->id,
                'printer_name' => $printer->name,
                'printer_type' => $printer->type,
                'items_count' => count($items),
            ]);
        } finally {
            $this->receiptRenderer->cleanup($imagePath);
        }
    }
}
