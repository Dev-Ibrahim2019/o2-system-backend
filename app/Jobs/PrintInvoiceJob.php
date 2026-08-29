<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
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
        public string $mode = 'all', // 'all' | 'merged' | 'departments'
    ) {}

    public function handle(OrderPrintingService $printingService): void
    {
        // نعبّي مين طبع الفاتورة ومتى بالذاكرة قبل الطباعة الفعلية، حتى تقدر
        // ورقة الفاتورة نفسها تعرض هالمعلومة (القيم بتتحفظ بقاعدة البيانات
        // بس بعد التأكد من نجاح الطباعة).
        $this->order->printed_by = $this->printedByUserId;
        $this->order->printed_at = now();
        if ($this->printedByUserId) {
            $this->order->setRelation('printedByUser', User::find($this->printedByUserId));
        }

        if ($this->printerId) {
            // طباعة موجّهة لطابعة محددة صراحة — فاتورة كاشير فقط، بدون تذاكر أقسام.
            $result = $printingService->printInvoiceById($this->order, $this->printerId);
            $success = $result['success'] ?? false;
            $results = [$result];
        } else {
            // وضع "محلي" — حسب $mode: مدمجة + أقسام / مدمجة فقط / أقسام فقط.
            $results = $printingService->printLocal($this->order, $this->mode);
            $success = collect($results)->every(fn($r) => $r['success'] ?? false);
        }

        if ($success) {
            $this->order->save();
        } else {
            Log::error('PrintInvoiceJob: print failed', [
                'order_id' => $this->order->id,
                'results'  => $results,
            ]);
        }
    }
}
