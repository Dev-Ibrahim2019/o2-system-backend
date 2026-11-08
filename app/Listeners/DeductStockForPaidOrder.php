<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Inventory\SaleConsumptionService;

/**
 * خصم المخزون حسب الوصفات عند دفع الطلب. متزامن (لا يوجد Queue worker في المشروع)، ويعمل بعد حفظ الدفع.
 * SaleConsumptionService::handlePaid لا يرمي أبداً: أي فشل يُسجَّل للمراجعة ولا يوقف الكاشير.
 */
class DeductStockForPaidOrder
{
    public function __construct(private SaleConsumptionService $consumption) {}

    public function handle(OrderPaid $event): void
    {
        $this->consumption->handlePaid($event->order);
    }
}
