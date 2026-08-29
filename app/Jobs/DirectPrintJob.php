<?php

namespace App\Jobs;

use App\Services\DirectPrintRoutingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DirectPrintJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public int $orderId,
        public int $cashierDeviceId,
        public ?int $printedByUserId = null,
        public ?string $closedAt = null,
    ) {}

    public function handle(DirectPrintRoutingService $service): void
    {
        $result = $service->execute(
            $this->orderId,
            $this->cashierDeviceId,
            $this->printedByUserId,
            $this->closedAt,
        );

        if (! ($result['success'] ?? false)) {
            Log::error('DirectPrintJob failed', [
                'order_id' => $this->orderId,
                'message'  => $result['message'] ?? null,
            ]);
        }
    }
}
