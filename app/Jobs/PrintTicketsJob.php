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

class PrintTicketsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public Order $order) {}

    public function handle(OrderPrintingService $printingService): void
    {
        $results = $printingService->printTickets($this->order);

        if (collect($results)->contains('success', false)) {
            Log::error('PrintTicketsJob: one or more department tickets failed', [
                'order_id' => $this->order->id,
                'results'  => $results,
            ]);
        }
    }
}
