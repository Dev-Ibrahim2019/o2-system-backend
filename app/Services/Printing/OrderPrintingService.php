<?php

namespace App\Services\Printing;

use App\Models\Order;
use App\Models\Printer;
use App\Models\ProductionTicket;
use App\Services\Printing\Renderers\ArabicReceiptRenderer;
use App\Services\PrintRoutingService;
use Illuminate\Support\Facades\Log;

/**
 * خدمة تنسيق طباعة الطلب
 * ────────────────────────
 * تتعامل مع:
 * 1. طباعة بون المطبخ (KOT) — تُوجه الأصناف للطابعات حسب قواعد التوجيه
 * 2. طباعة فاتورة الكاشير — تطبع الأصناف الموجهة لطابعة الكاشير فقط
 * 3. printOrder() — الدالة الموحدة التي تدير كلTHING
 */
class OrderPrintingService
{
    private PrinterService $printerService;
    private PrintRoutingService $routingService;
    private ArabicReceiptRenderer $receiptRenderer;

    public function __construct(
        PrinterService $printerService,
        PrintRoutingService $routingService,
        ArabicReceiptRenderer $receiptRenderer
    ) {
        $this->printerService = $printerService;
        $this->routingService = $routingService;
        $this->receiptRenderer = $receiptRenderer;
    }

    /**
     * الدالة الموحدة لطباعة الطلب.
     *
     * تحدد تلقائياً:
     * - أصناف المطبخ (KITCHEN printers) → بون مطبخ (KOT)
     * - أصناف الكاشير (CASHIER printers) → فاتورة كاشير
     *
     * @param  Order     $order      الطلب
     * @param  ?int      $userId     ID المستخدم المرسل
     * @param  ?string   $deviceType نوع الجهاز: 'POS' | 'WAITER_APP' | null
     * @param  ?int      $deviceId   ID الجهاز
     * @return array                 نتائج الطباعة لكل طابعة
     */
    public function printOrder(
        Order $order,
        ?int $userId = null,
        ?string $deviceType = null,
        ?int $deviceId = null
    ): array {
        $items = $this->prepareOrderItems($order);
        $results = [];

        // توجيه الأصناف للطابعات
        $routedGroups = $this->routingService->routeOrder(
            $order->branch_id,
            $items,
            $userId,
            $deviceType,
            $deviceId
        );

        foreach ($routedGroups as $group) {
            $printer = $group['printer'];
            $groupItems = $group['items'];

            if ($printer->type === 'CASHIER') {
                // طابعة كاشير → فاتورة (invoice) بالأصناف الموجهة فقط
                $result = $this->printInvoiceForItems($order, $printer, $groupItems);
            } else {
                // طابعة مطبخ/بار → بون مطبخ (KOT) بالأصناف الموجهة فقط
                $result = $this->printKotForItems($order, $printer, $groupItems);
            }

            $results[] = array_merge($result, [
                'printer_id'   => $printer->id,
                'printer_name' => $printer->name,
                'printer_type' => $printer->type,
                'items_count'  => count($groupItems),
            ]);
        }

        return $results;
    }

    /**
     * طباعة بونات المطبخ (KOT) — الطريقة القديمة المحدثة.
     * تستخدم sectionsForPrint() لتقسيم الأصناف للأقسام.
     */
    public function printKot(Order $order, ?int $userId = null): array
    {
        $sections = $order->sectionsForPrint();
        $results = [];

        foreach ($sections as $section) {
            $deptId = $section['department_id'];
            $items = $section['items'];

            if (empty($items)) {
                continue;
            }

            $printer = $this->resolvePrinterForDepartment(
                $order->branch_id,
                $deptId,
                $items,
                $userId
            );

            if ($printer === null) {
                Log::warning("No printer found for department {$deptId} in order {$order->id}");
                $results[] = [
                    'success' => false,
                    'department_id' => $deptId,
                    'message' => 'لا توجد طابعة مخصصة لهذا القسم',
                ];
                continue;
            }

            $imagePath = $this->receiptRenderer->renderKot(
                $order,
                $printer->name,
                $items
            );

            $result = $this->printerService->printReceiptImage($printer, $imagePath);
            $this->receiptRenderer->cleanup($imagePath);

            $result['department_id'] = $deptId;
            $result['department_name'] = $section['department']['name'] ?? null;
            $results[] = $result;
        }

        return $results;
    }

    /**
     * طباعة فاتورة كاشير لطلب على طابعة محددة.
     */
    public function printInvoice(Order $order, Printer $printer): array
    {
        $imagePath = $this->receiptRenderer->render($order);
        $result = $this->printerService->printReceiptImage($printer, $imagePath);
        $this->receiptRenderer->cleanup($imagePath);

        return $result;
    }

    /**
     * طباعة فاتورة كاشير by printer ID.
     */
    public function printInvoiceById(Order $order, int $printerId): array
    {
        $printer = Printer::find($printerId);

        if (!$printer) {
            return ['success' => false, 'message' => 'الطابعة غير موجودة'];
        }

        return $this->printInvoice($order, $printer);
    }

    /**
     * طباعة فاتورة كاشير للطابعة الافتراضية.
     */
    public function printInvoiceToCashier(Order $order): array
    {
        $printer = Printer::where('branch_id', $order->branch_id)
            ->where('type', 'CASHIER')
            ->where('is_active', true)
            ->first();

        if (!$printer) {
            return ['success' => false, 'message' => 'لا توجد طابعة كاشير مفعّلة لهذا الفرع'];
        }

        return $this->printInvoice($order, $printer);
    }

    /**
     * طباعة تذاكر الأقسام (ticket.blade.php) — كل قسم لحال.
     * تطبع تذكرة تحضير لكل قسم في الطلب على الطابعة المخصصة للقسم.
     */
    public function printTickets(Order $order): array
    {
        $tickets = $order->tickets()
            ->with(['department', 'ticketItems.orderItem'])
            ->whereIn('status', ['pending', 'preparing'])
            ->get();

        if ($tickets->isEmpty()) {
            return [[
                'success' => false,
                'message' => 'لا توجد تذاكر أقسام مطبوعة لهذا الطلب',
            ]];
        }

        $results = [];

        foreach ($tickets as $ticket) {
            $deptId = $ticket->department_id;

            if ($ticket->ticketItems->isEmpty()) {
                continue;
            }

            $printer = $this->resolvePrinterForDepartment(
                $order->branch_id,
                $deptId,
                $ticket->ticketItems->map(fn($ti) => [
                    'item_id'     => $ti->orderItem?->item_id,
                    'category_id' => $deptId,
                    'name'        => $ti->orderItem?->item_name_ar ?? $ti->orderItem?->item_name,
                    'quantity'    => $ti->quantity,
                    'price'       => $ti->orderItem?->price ?? 0,
                    'total'       => $ti->orderItem?->total ?? 0,
                    'notes'       => $ti->notes ?? $ti->orderItem?->notes,
                ])->toArray(),
                null
            );

            if ($printer === null) {
                Log::warning("No printer found for department {$deptId} in order {$order->id}");
                $results[] = [
                    'success' => false,
                    'department_id' => $deptId,
                    'department_name' => $ticket->department?->name,
                    'message' => 'لا توجد طابعة مخصصة لهذا القسم',
                ];
                continue;
            }

            $imagePath = $this->receiptRenderer->renderTicket($order, $ticket);
            $result = $this->printerService->printReceiptImage($printer, $imagePath);
            $this->receiptRenderer->cleanup($imagePath);

            $results[] = array_merge($result, [
                'printer_id'      => $printer->id,
                'printer_name'    => $printer->name,
                'department_id'   => $deptId,
                'department_name' => $ticket->department?->name,
                'ticket_id'       => $ticket->id,
                'items_count'     => $ticket->ticketItems->count(),
            ]);
        }

        return $results;
    }

    // ── Private Helpers ─────────────────────────────────────────

    /**
     * تحضير أصناف الطلب كمصفوفة مسطحة للتوجيه.
     */
    private function prepareOrderItems(Order $order): array
    {
        return $order->items->map(fn($item) => [
            'item_id'     => $item->item_id,
            'category_id' => $item->department_id,
            'name'        => $item->item_name_ar ?? $item->item_name,
            'name_ar'     => $item->item_name_ar,
            'quantity'    => $item->quantity,
            'price'       => $item->price,
            'total'       => $item->total,
            'notes'       => $item->notes,
        ])->toArray();
    }

    /**
     * طباعة فاتورة كاشير بأصناف محددة فقط (مفلترة).
     * يبني كائن Order وهمي يحتوي فقط على الأصناف المطلوبة.
     */
    private function printInvoiceForItems(
        Order $order,
        Printer $printer,
        array $items
    ): array {
        // بناء صورة الفاتورة مع الأصناف المفلترة فقط
        $imagePath = $this->receiptRenderer->renderFilteredInvoice(
            $order,
            $printer->name,
            $items
        );

        $result = $this->printerService->printReceiptImage($printer, $imagePath);
        $this->receiptRenderer->cleanup($imagePath);

        return $result;
    }

    /**
     * طباعة بون مطبخ بأصناف محددة فقط.
     */
    private function printKotForItems(
        Order $order,
        Printer $printer,
        array $items
    ): array {
        $imagePath = $this->receiptRenderer->renderKot(
            $order,
            $printer->name,
            $items
        );

        $result = $this->printerService->printReceiptImage($printer, $imagePath);
        $this->receiptRenderer->cleanup($imagePath);

        return $result;
    }

    /**
     * تحديد الطابعة لقسم معين (للاستخدام مع printKot).
     */
    private function resolvePrinterForDepartment(
        int $branchId,
        ?int $departmentId,
        array $items,
        ?int $userId
    ): ?Printer {
        $routedItems = $this->routingService->routeOrder($branchId, $items, $userId);

        if (!empty($routedItems)) {
            return $routedItems[0]['printer'];
        }

        return Printer::where('branch_id', $branchId)
            ->where('type', 'KITCHEN')
            ->where('is_active', true)
            ->first();
    }
}
