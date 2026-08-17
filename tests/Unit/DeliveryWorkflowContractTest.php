<?php
namespace Tests\Unit;
use App\Models\Order;
use App\Services\Delivery\OrderCancellationService;
use PHPUnit\Framework\TestCase;
class DeliveryWorkflowContractTest extends TestCase
{
    public function test_canonical_statuses_and_cancellation_reasons_are_exposed():void
    {
        $this->assertSame('READY_FOR_DELIVERY',Order::STATUS_READY_FOR_DELIVERY);
        $this->assertSame('FAILED_DELIVERY',Order::STATUS_FAILED_DELIVERY);
        $this->assertContains('customer_cancelled',OrderCancellationService::REASONS);
        $this->assertContains('other',OrderCancellationService::REASONS);
    }
}
