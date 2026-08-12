<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Printing\OrderPrintingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PrintInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 90;

    public function __construct(
        public Order $order,
        public ?int $printerId,
        public ?int $printedByUserId,
    ) {}

    public function handle(OrderPrintingService $printingService): void
    {
        $result = $this->printerId
            ? $printingService->printInvoiceById($this->order, $this->printerId)
            : $printingService->printInvoiceToCashier($this->order);

        if ($result['success'] ?? false) {
            $this->order->update([
                'printed_by' => $this->printedByUserId,
                'printed_at' => now(),
            ]);
        } else {
            Log::error('PrintInvoiceJob: print failed', [
                'order_id' => $this->order->id,
                'message'  => $result['message'] ?? null,
            ]);
        }
    }
}
