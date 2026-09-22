<?php

namespace App\Services\CallCenter;

use App\Models\{CallTicket, Customer, CustomerAddress, CustomerNote, Item, Order, OrderItem, User};
use App\Services\Crm\IdentityConflictService;
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
        private readonly IdentityConflictService $conflicts,
    ) {}

    public function create(array $data, User $agent): array
    {
        return DB::transaction(function () use ($data, $agent) {
            $ticket = ! empty($data['call_ticket_id'])
                ? CallTicket::lockForUpdate()->findOrFail($data['call_ticket_id'])
                : null;
            // branch_id = null يعني "يخدم كل الفروع" (نفس اصطلاح BranchScope) — لموظفي/مدير
            // الكول سنتر تحديدًا، الحساب دائمًا بلا فرع مفروض عمدًا (دور مركزي)، فليس تقييدًا.
            $agentServesAllBranches = $agent->hasRole('super-admin') || is_null($agent->branch_id);
            if (! $agentServesAllBranches && (int) $agent->branch_id !== (int) $data['branch_id']) {
                throw ValidationException::withMessages(['branch_id' => 'الفرع المحدد غير مسموح لهذا المستخدم.']);
            }

            $customer = ! empty($data['customer_id'])
                ? Customer::lockForUpdate()->findOrFail($data['customer_id'])
                : null;
            $conflictCandidate = null;

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

                // Matched an existing customer whose stored name differs from
                // the one the agent just typed? The stored name still wins —
                // the line above is untouched — but the discrepancy is no
                // longer discarded silently. Queued here, raised after the
                // order exists so the ticket can point at it.
                if ($resolved['customer']) {
                    $conflictCandidate = [
                        'customer' => $resolved['customer'],
                        'name' => $data['customer']['name'] ?? null,
                        'phone' => $data['customer']['phone'],
                    ];
                }
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
                // Preserves what the agent actually typed when it differed —
                // the stored name still wins above, this only stops the typed
                // one from vanishing.
                'incoming_customer_name' => $conflictCandidate
                    ? $this->conflicts->incomingNameFor($customer, $conflictCandidate['name'])
                    : null,
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
                $this->createItem($order, $row, $customer, $agent);
            }
            $order->recalculateTotals();

            // What the agent typed in the order's notes field only lived on
            // orders.note before this — invisible on the customer's own CRM
            // profile (CrmController::notes(), a separate customer_notes
            // table) even though it was about that same customer. Mirrored
            // here, order_id-linked, so it shows up in both places without
            // duplicating what the agent has to type.
            if (filled($data['notes'] ?? null)) {
                CustomerNote::create([
                    'customer_id' => $customer->id,
                    'order_id' => $order->id,
                    'content' => $data['notes'],
                    'type' => 'general',
                    'created_by' => $agent->id,
                ]);
            }

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

            if ($conflictCandidate) {
                // Shared entry point — it owns the afterCommit scheduling, so
                // this channel no longer wires it by hand. Same behaviour,
                // one implementation for every channel.
                $this->conflicts->queueIfConflicting(
                    customer: $conflictCandidate['customer'],
                    incomingName: $conflictCandidate['name'],
                    incomingPhone: $conflictCandidate['phone'],
                    channel: 'call_center',
                    orderId: $order->id,
                );
            }

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
        if (! empty($data['customer_address_id'])) {
            return $customer->addresses()->whereKey($data['customer_address_id'])->firstOrFail();
        }

        // Delivery genuinely needs a resolvable address to dispatch a driver
        // to, so city+area stay required there. Every other order type used
        // to just drop whatever the agent typed the moment it wasn't a
        // delivery order — an address typed while taking a takeaway/dine-in
        // call is exactly as real as one typed for a delivery call, and
        // should end up on the customer's saved addresses either way.
        if ($orderType === 'delivery' && (blank($data['city'] ?? null) || blank($data['area'] ?? null))) {
            throw ValidationException::withMessages(['address' => 'المدينة والمنطقة مطلوبتان لطلب التوصيل.']);
        }

        $hasLocation = filled($data['city'] ?? null) || filled($data['area'] ?? null)
            || filled($data['street'] ?? null) || filled($data['landmark'] ?? null);
        if (! $hasLocation) {
            return null;
        }

        return $customer->addresses()->create([
            'label' => $data['label'] ?? 'المنزل',
            'city' => $data['city'] ?? null,
            'area' => $data['area'] ?? null,
            'street' => $data['street'] ?? null,
            'landmark' => $data['landmark'] ?? null,
            'delivery_notes' => $data['delivery_notes'] ?? null,
            'phone' => $customer->phone ?: $customer->mobile,
            'is_default' => ! $customer->addresses()->exists(),
            'is_active' => true,
        ]);
    }

    private function createItem(Order $order, array $row, Customer $customer, User $agent): void
    {
        $item = Item::findOrFail($row['item_id']);
        $price = $row['unit_price'] ?? $item->priceForBranch($order->branch_id);
        if ($price === null || ! $item->department_id) {
            throw ValidationException::withMessages(['items' => 'أحد الأصناف غير متاح في الفرع المحدد.']);
        }
        $notes = $row['notes'] ?? null;
        OrderItem::create([
            'order_id' => $order->id,
            'created_by' => $agent->id,
            // order_items keeps $timestamps = false — set explicitly or the
            // order timeline can't tell items apart by when they were added.
            'created_at' => now(),
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
            'notes' => $notes,
        ]);

        // Same mirroring as OrderController::createOrderItem() — an item
        // note ("بدون زيتون") is a customer preference, not just a kitchen
        // instruction for this one order.
        if (filled($notes)) {
            CustomerNote::create([
                'customer_id' => $customer->id,
                'order_id' => $order->id,
                'content' => "{$item->name}: {$notes}",
                'type' => 'preference',
                'created_by' => $agent->id,
            ]);
        }
    }

    private function addressSnapshot(?CustomerAddress $address): ?array
    {
        return $address?->only(['label', 'city', 'area', 'district', 'street', 'landmark', 'building_no', 'floor', 'apartment', 'delivery_notes']);
    }
}
