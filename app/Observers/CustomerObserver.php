<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\Accounting\AccountCreationService;
use Illuminate\Support\Facades\Log;

class CustomerObserver
{
    public function __construct(
        private readonly AccountCreationService $accountCreationService,
    ) {}

    public function created(Customer $customer): void
    {
        try {
            $this->accountCreationService->createForCustomer($customer);
        } catch (\Throwable $e) {
            Log::error("فشل إنشاء حساب العميل [{$customer->id}]: {$e->getMessage()}");
        }
    }
}
