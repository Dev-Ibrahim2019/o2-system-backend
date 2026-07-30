<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\StoreOrderFeedbackRequest;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class OrderFeedbackController extends ApiController
{
    public function show(Customer $customer, Order $order): JsonResponse
    {
        $this->assertOwnership($customer, $order);

        return $this->success(
            'تم تحميل تقييم الطلب',
            $order->feedback()->with('recorder:id,name')->first(),
        );
    }

    public function store(StoreOrderFeedbackRequest $request, Customer $customer, Order $order): JsonResponse
    {
        $this->assertOwnership($customer, $order);
        $data = $request->validated();
        if ($order->order_type !== 'delivery') {
            $data['delivery_speed'] = null;
        }

        $feedback = $order->feedback()->updateOrCreate(
            ['order_id' => $order->id],
            [...$data, 'customer_id' => $customer->id, 'recorded_by' => $request->user()->id],
        );

        return $this->success(
            $feedback->wasRecentlyCreated ? 'تم حفظ تقييم الطلب' : 'تم تحديث تقييم الطلب',
            $feedback->load('recorder:id,name'),
            $feedback->wasRecentlyCreated ? 201 : 200,
        );
    }

    private function assertOwnership(Customer $customer, Order $order): void
    {
        abort_unless((int) $order->customer_id === (int) $customer->id, 404);
    }
}
