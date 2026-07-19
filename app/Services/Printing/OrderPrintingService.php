<?php

namespace App\Services\Printing;

use App\Models\Order;
use App\Models\Printer;
use App\Services\Printing\Renderers\ArabicReceiptRenderer;
use App\Services\PrintRoutingService;
use Illuminate\Support\Facades\Log;

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
     * Print KOT (Kitchen Order Ticket) for an order.
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

            // Use the new KOT renderer that takes Order + printer name + section items
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
     * Print invoice receipt for an order on a specific printer.
     */
    public function printInvoice(Order $order, Printer $printer): array
    {
        $imagePath = $this->receiptRenderer->render($order);

        $result = $this->printerService->printReceiptImage($printer, $imagePath);
        $this->receiptRenderer->cleanup($imagePath);

        return $result;
    }

    /**
     * Print invoice by printer ID (loaded from database).
     */
    public function printInvoiceById(Order $order, int $printerId): array
    {
        $printer = Printer::find($printerId);

        if (!$printer) {
            return [
                'success' => false,
                'message' => 'الطابعة غير موجودة',
            ];
        }

        return $this->printInvoice($order, $printer);
    }

    /**
     * Print invoice to the default cashier printer for the order's branch.
     */
    public function printInvoiceToCashier(Order $order): array
    {
        $printer = Printer::where('branch_id', $order->branch_id)
            ->where('type', 'CASHIER')
            ->where('is_active', true)
            ->first();

        if (!$printer) {
            return [
                'success' => false,
                'message' => 'لا توجد طابعة كاشير مفعّلة لهذا الفرع',
            ];
        }

        return $this->printInvoice($order, $printer);
    }

    // ── Private Helpers ─────────────────────────────────────

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
