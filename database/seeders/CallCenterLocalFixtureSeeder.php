<?php

namespace Database\Seeders;

use App\Models\{Branch, Customer, Item, Order, OrderItem};
use App\Services\CustomerIdentityService;
use Illuminate\Database\Seeder;
use RuntimeException;

class CallCenterLocalFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Call-center fixture is restricted to local/testing environments.');
        }

        $branch = Branch::query()->firstOrFail();
        $customer = Customer::query()
            ->where('phone', '599001122')
            ->orWhereHas('phones', fn ($query) => $query->where('normalized_phone', '+970599001122'))
            ->first();

        if (! $customer) {
            $customer = app(CustomerIdentityService::class)->create([
                'name' => 'عميل اختبار الكول سنتر',
                'phone' => '0599001122',
                'branch_id' => $branch->id,
                'status' => 'active',
            ]);
        }

        $address = $customer->addresses()->firstOrCreate(
            ['label' => 'عنوان اختبار الكول سنتر'],
            [
                'city' => 'غزة',
                'area' => 'الرمال',
                'street' => 'شارع الاختبار',
                'landmark' => 'بجوار نقطة الاختبار',
                'is_default' => true,
                'is_active' => true,
            ],
        );

        if (! $customer->orders()->withoutGlobalScopes()->where('note', '[LOCAL CALL CENTER FIXTURE]')->exists()) {
            $order = Order::withoutGlobalScopes()->create([
                'order_number' => Order::generateOrderNumber(),
                'branch_id' => $branch->id,
                'order_type' => 'delivery',
                'source' => 'call_center',
                'status' => 'paid',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'customer_address_id' => $address->id,
                'delivery_address_snapshot' => $address->only(['label', 'city', 'area', 'street', 'landmark']),
                'note' => '[LOCAL CALL CENTER FIXTURE]',
                'subtotal' => 0,
                'discount_value' => 0,
                'discount_type' => 'amount',
                'discount_amount' => 0,
                'engine_discount_amount' => 0,
                'total' => 0,
            ]);

            $item = Item::query()->whereNotNull('department_id')->first();
            if ($item) {
                $price = $item->priceForBranch($branch->id) ?? 1;
                OrderItem::create([
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'department_id' => $item->department_id,
                    'item_name' => $item->name,
                    'item_name_ar' => $item->name_ar ?? $item->name,
                    'price' => $price,
                    'original_price' => $price,
                    'final_price' => $price,
                    'quantity' => 1,
                    'total' => $price,
                    'status' => 'pending',
                ]);
                $order->recalculateTotals();
            }
        }
    }
}
