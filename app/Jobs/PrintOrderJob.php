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

class PrintOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public Order $order,
        public ?int $userId,
        public ?string $deviceType,
        public ?int $deviceId,
    ) {}

    public function handle(OrderPrintingService $printingService): void
    {
        $results = $printingService->printOrder(
            $this->order,
            $this->userId,
            $this->deviceType,
            $this->deviceId,
        );

        if (collect($results)->contains('success', false)) {
            Log::error('PrintOrderJob: one or more printers failed', [
                'order_id' => $this->order->id,
                'results'  => $results,
            ]);
        }
    }
}
