<?php

namespace App\Services\CallCenter;

use App\Models\{CallTicket, Customer, CustomerAddress, Item, Order, OrderItem, User};
use App\Services\CustomerIdentityService;
use App\Services\Integration\IntegrationOutboxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CallCenterOrderCreationService
{
    public function __construct(
        private readonly CustomerResolutionService $resolution,
        private readonly CustomerIdentityService $identity,
        private readonly IntegrationOutboxWriter $outbox,
    ) {}

    public function create(array $data, User $agent): array
    {
        return DB::transaction(function () use ($data, $agent) {
            $ticket = ! empty($data['call_ticket_id'])
                ? CallTicket::lockForUpdate()->findOrFail($data['call_ticket_id'])
                : null;
            if (! $agent->hasRole('super-admin') && (int) $agent->branch_id !== (int) $data['branch_id']) {
                throw ValidationException::withMessages(['branch_id' => 'الفرع المحدد غير مسموح لهذا المستخدم.']);
            }

            $customer = ! empty($data['customer_id'])
                ? Customer::lockForUpdate()->findOrFail($data['customer_id'])
                : null;

            if (! $customer) {
                $resolved = $this->resolution->resolve($data['customer']['phone'], true);
                if ($resolved['status'] === 'multiple') {
                    throw ValidationException::withMessages([
                        'customer.phone' => 'يوجد أكثر من عميل مرتبط بهذا الرقم. اختر العميل الصحيح أولًا.',
                    ]);
                }
                $customer = $resolved['customer'] ?: $this->identity->create([
                    'name' => trim($data['customer']['name']),
                    'phone' => $data['customer']['phone'],
                    'branch_id' => $data['branch_id'],
                    'status' => 'active',
                ], Customer::TYPE_OPERATIONAL);
            }

            $address = $this->resolveAddress($customer, $data['address'] ?? [], $data['order_type']);
            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'branch_id' => $data['branch_id'],
                'call_center_agent_id' => $agent->id,
                'order_type' => $data['order_type'],
                'source' => 'call_center',
                'status' => 'pending',
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone ?: $customer->mobile,
                'customer_address_id' => $address?->id,
                'delivery_zone_id' => $data['delivery_zone_id'] ?? null,
                'delivery_fee' => $data['delivery_fee'] ?? 0,
                'delivery_address_snapshot' => $data['delivery_address_snapshot'] ?? $this->addressSnapshot($address),
                'delivery_notes' => $data['address']['delivery_notes'] ?? null,
                'call_notes' => $ticket
                    ? "Ticket {$ticket->id}".(! empty($data['external_call_id']) ? " / Call {$data['external_call_id']}" : '')
                    : 'Manual call-center invoice',
                'note' => $data['notes'] ?? null,
                'subtotal' => 0,
                'discount_value' => $data['discount_value'] ?? 0,
                'discount_type' => $data['discount_type'] ?? 'amount',
                'discount_amount' => 0,
                'engine_discount_amount' => 0,
                'total' => 0,
            ]);

            foreach ($data['items'] as $row) {
                $this->createItem($order, $row);
            }
            $order->recalculateTotals();

            $this->outbox->record(
                eventType: 'order.created',
                aggregateType: 'order',
                aggregateRef: $order->public_ref,
                payload: [
                    'public_order_ref' => $order->public_ref,
                    'order_number' => $order->order_number,
                    'source' => $order->source,
                ],
            );

            $ticket?->update([
                'branch_id' => $data['branch_id'],
                'customer_id' => $customer->id,
                'linked_order_id' => $order->id,
                'agent_id' => $agent->id,
            ]);

            return [
                'customer' => $customer->fresh(['branch:id,name', 'primaryPhone']),
                'address' => $address?->fresh(),
                'order' => $order->fresh(['items.department', 'invoice']),
                'call_ticket' => $ticket?->fresh(),
            ];
        }, 3);
    }

    private function resolveAddress(Customer $customer, array $data, string $orderType): ?CustomerAddress
    {
        if ($orderType !== 'delivery') {
            return null;
        }
        if (! empty($data['customer_address_id'])) {
            return $customer->addresses()->whereKey($data['customer_address_id'])->firstOrFail();
        }
        if (blank($data['city'] ?? null) || blank($data['area'] ?? null)) {
            throw ValidationException::withMessages(['address' => 'المدينة والمنطقة مطلوبتان لطلب التوصيل.']);
        }

        return $customer->addresses()->create([
            'label' => $data['label'] ?? 'المنزل',
            'city' => $data['city'],
            'area' => $data['area'],
            'street' => $data['street'] ?? null,
            'landmark' => $data['landmark'] ?? null,
            'delivery_notes' => $data['delivery_notes'] ?? null,
            'phone' => $customer->phone ?: $customer->mobile,
            'is_default' => ! $customer->addresses()->exists(),
            'is_active' => true,
        ]);
    }

    private function createItem(Order $order, array $row): void
    {
        $item = Item::findOrFail($row['item_id']);
        $price = $row['unit_price'] ?? $item->priceForBranch($order->branch_id);
        if ($price === null || ! $item->department_id) {
            throw ValidationException::withMessages(['items' => 'أحد الأصناف غير متاح في الفرع المحدد.']);
        }
        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $item->id,
            'department_id' => $item->department_id,
            'item_name' => $item->name,
            'item_name_ar' => $item->name_ar ?? $item->name,
            'price' => $price,
            'original_price' => $price,
            'final_price' => $price,
            'quantity' => $row['quantity'],
            'total' => round($price * $row['quantity'], 2),
            'status' => 'pending',
            'notes' => $row['notes'] ?? null,
        ]);
    }

    private function addressSnapshot(?CustomerAddress $address): ?array
    {
        return $address?->only(['label', 'city', 'area', 'district', 'street', 'landmark', 'building_no', 'floor', 'apartment', 'delivery_notes']);
    }
}
