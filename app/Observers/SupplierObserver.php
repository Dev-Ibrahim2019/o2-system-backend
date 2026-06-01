<?php

namespace App\Observers;

use App\Models\Supplier;
use App\Services\Accounting\AccountCreationService;
use Illuminate\Support\Facades\Log;

class SupplierObserver
{
    public function __construct(
        private readonly AccountCreationService $accountCreationService,
    ) {}

    public function created(Supplier $supplier): void
    {
        try {
            $this->accountCreationService->createForSupplier($supplier);
        } catch (\Throwable $e) {
            Log::error("فشل إنشاء حساب المورد [{$supplier->id}]: {$e->getMessage()}");
        }
    }
}
