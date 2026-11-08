<?php

namespace App\Console\Commands;

use App\Services\Inventory\SaleConsumptionService;
use Illuminate\Console\Command;

/** إعادة خصم المخزون للطلبات المدفوعة التي فشل خصمها أو لم يُسجَّل (نفس زر "إعادة المحاولة" في الإعدادات). */
class PostSalesStockDeduction extends Command
{
    protected $signature = 'inventory:post-sales {--limit=500 : أقصى عدد طلبات في التشغيلة}';

    protected $description = 'خصم المخزون حسب الوصفات للطلبات المدفوعة التي فشل خصمها أو لم يُسجَّل';

    public function handle(SaleConsumptionService $consumption): int
    {
        $result = $consumption->retryPending((int) $this->option('limit'));
        $this->info("خُصم: {$result['posted']} — فشل: {$result['failed']}");

        return self::SUCCESS;
    }
}
