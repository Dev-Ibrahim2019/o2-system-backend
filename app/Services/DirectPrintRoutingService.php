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
 *  القاعدة (وضع "فوري"):
 *  - كل قسم يطبع تيكيت منفصل خاص فيه فقط، على طابعته الخاصة.
 *  - لا يوجد أي تيكيت أو فاتورة مجمّعة للكاشير في هذا الوضع إطلاقاً —
 *    هذا حصراً لوضع "محلي" (راجع OrderPrintingService::printLocal).
 */
class DirectPrintRoutingService
{
    private PrinterService $printerService;
    private ArabicReceiptRenderer $receiptRenderer;

    /** @var OrderItem[] أصناف ما انطبعت لأنه ما في طابعة مربوطة فيها ولا بقسمها */
    private array $unmappedItems = [];

    /** اسم المستخدم/الكاشير الذي نفّذ الطباعة — يُطبع على التيكيت */
    private ?string $printedByName = null;

    /** وقت إغلاق الفاتورة الفوري — يُطبع على التيكيت */
    private ?\Illuminate\Support\Carbon $closedAt = null;

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
    public function execute(
        int $orderId,
        int $cashierDeviceId,
        ?int $printedByUserId = null,
        ?string $closedAt = null
    ): array {
        $this->unmappedItems = [];
        $this->printedByName = $printedByUserId
            ? \App\Models\User::whereKey($printedByUserId)->value('name')
            : null;

        // ── 1. جلب الطلب ──
        $order = Order::with(['items.department', 'branch', 'invoice'])->find($orderId);

        // وقت الإغلاق: الوقت الممرَّر من لحظة "تنفيذ وطباعة"، وإلا وقت الدفع/التسليم
        // الفعلي إن وُجد (عند إعادة الطباعة لاحقاً)، وإلا الآن.
        $this->closedAt = $closedAt
            ? \Illuminate\Support\Carbon::parse($closedAt)
            : ($order?->paid_at
                ?? $order?->delivered_at
                ?? optional($order?->invoice)->closed_at
                ?? now());

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

        // ── 5. توليد أوامر الطباعة — تيكيت منفصل خاص بكل قسم على طابعته الخاصة ──
        // (وضع "فوري": لا يوجد أي تيكيت أو فاتورة مجمّعة للكاشير هون إطلاقاً)
        $printJobs = $this->buildDepartmentPrintJobs(
            $order, $itemPrinterMap, $printers
        );

        // ── 6. تنفيذ الطباعة فعلياً ──
        if (empty($printJobs)) {
            Log::info('DirectPrint: no department print jobs generated', [
                'order_id' => $orderId,
                'cashier_device_id' => $cashierDeviceId,
            ]);

            return [
                'success' => false,
                'message' => 'لا توجد طابعة نشطة مرتبطة بهذا الطلب أو هذا الجهاز',
                'print_jobs' => [],
                'printed_items_count' => 0,
            ];
        }

        $results = $this->executePrintJobs($printJobs);

        // ── 7. تحديث حالة الأصناف — فقط إذا نجحت كل أوامر الطباعة فعلياً ──
        // (تعليم الصنف "مطبوع" قبل التأكد من نجاح الإرسال للطابعة كان يعني إنه
        // أي فشل بالطباعة (طابعة غير موجودة/متوقفة) يمنع أي محاولة إعادة طباعة لاحقة).
        $allSucceeded = collect($results)->every(fn($r) => $r['success'] ?? false);

        // الأصناف يلي فعلياً انضافت لأمر طباعة (مش الأصناف بلا طابعة — هاي لازم
        // تضل is_printed_direct=false عشان تنعاد محاولة طباعتها لاحقاً بدل ما
        // تنعلّم "مطبوعة" وهي أصلاً ما وصلت لأي طابعة).
        $attemptedItemIds = collect($itemPrinterMap)->pluck('item.id')->unique()->values()->toArray();

        $printedItemIds = [];
        if ($allSucceeded && !empty($attemptedItemIds)) {
            $printedItemIds = $attemptedItemIds;
            OrderItem::whereIn('id', $printedItemIds)
                ->update(['is_printed_direct' => true]);
        }

        $unmappedCount = count($this->unmappedItems);
        $message = $allSucceeded
            ? 'تمت معالجة الطباعة الفورية'
            : 'فشلت الطباعة على طابعة واحدة أو أكثر';
        if ($unmappedCount > 0) {
            $message .= " — تنبيه: {$unmappedCount} صنف بدون طابعة مربوطة، لم تتم طباعتها.";
        }

        return [
            'success' => $allSucceeded && $unmappedCount === 0,
            'message' => $message,
            'unmapped_items' => collect($this->unmappedItems)->map(fn($item) => [
                'order_item_id' => $item->id,
                'item_id' => $item->item_id,
                'item_name_ar' => $item->item_name_ar ?: $item->item_name,
                'department_id' => $item->department_id,
            ])->values()->all(),
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
            } else {
                // ما في طابعة مربوطة بهذا الصنف ولا بقسمه — كان هذا يختفي بصمت
                // وتظهر الطباعة "ناجحة" رغم إنه هالصنف ما وصل لأي طابعة إطلاقاً.
                $this->unmappedItems[] = $item;
                Log::warning('DirectPrint: item has no mapped printer', [
                    'order_item_id' => $item->id,
                    'item_id' => $item->item_id,
                    'item_name' => $item->item_name_ar ?: $item->item_name,
                    'department_id' => $item->department_id,
                ]);
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
                $results[] = $this->printDepartmentKot($job);
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
     * طباعة KOT لطابعة القسم — تيكيت واحد يحتوي كل الأصناف
     */
    private function printDepartmentKot(array $job): array
    {
        $printer = $job['printer'];
        $order = $job['order'];
        $items = $job['items'];

        $meta = [
            'printed_by'    => $this->printedByName,
            'closed_at'     => $this->closedAt,
            'order_total'   => (float) ($order->total ?? 0),
            'section_total' => array_sum(array_map(
                fn($i) => (float) ($i['total'] ?? 0),
                $items
            )),
        ];

        $imagePath = $this->receiptRenderer->renderKot(
            $order,
            $printer->name,
            $items,
            $meta
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
