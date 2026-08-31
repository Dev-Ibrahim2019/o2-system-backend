<?php

namespace App\Services\CallCenter;

use App\Models\CallTicket;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerComplaint;
use App\Models\CustomerNote;
use App\Models\CustomerOccasion;
use App\Models\ComplaintFollowup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CustomerIdentityService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CallCenterService
{
    public function __construct(
        private readonly CustomerIdentityService $customerIdentity,
    ) {}

    public function getActiveOrders(?int $branchId = null): array
    {
        $orders = Order::query()
            ->where('source', 'call_center')
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->whereNotIn('status', ['cancelled', 'canceled', 'served'])
            ->withCount('tickets')
            ->with('branch:id,name')
            ->latest()
            ->limit(100)
            ->get();

        $rows = $orders->map(function (Order $order) {
            $scopes = self::classifyActiveOrder(
                $order->status,
                $order->order_type,
                (int) $order->tickets_count,
            );
            // "بلا فرع" محسوبة هنا (وليست في classifyActiveOrder المشترك) لتفادي كسر
            // المستدعين الآخرين لهذه الدالة الذين لا يمرّرون فرعاً أصلاً ولا يعنيهم الأمر
            if ($scopes !== [] && ! $order->branch_id) {
                $scopes[] = 'no_branch';
            }

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'order_type' => $order->order_type,
                'customer_id' => $order->customer_id,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'total' => (float) $order->total,
                'branch' => $order->branch,
                'created_at' => $order->created_at,
                'scopes' => $scopes,
            ];
        })->filter(fn (array $row) => $row['scopes'] !== [])->values();

        return [
            'operational_active' => $rows->filter(fn ($row) => in_array('operational_active', $row['scopes'], true))->values(),
            'awaiting_payment' => $rows->filter(fn ($row) => in_array('awaiting_payment', $row['scopes'], true))->values(),
            'kitchen_active' => $rows->filter(fn ($row) => in_array('kitchen_active', $row['scopes'], true))->values(),
            'delivery_active' => $rows->filter(fn ($row) => in_array('delivery_active', $row['scopes'], true))->values(),
            // طلبات بلا فرع (حالات قديمة/طارئة — إنشاء الطلب صار يمنع هذا الآن) تبقى ظاهرة
            // في نطاقها الطبيعي (awaiting_payment مثلاً) وتُضاف هنا أيضاً كتبويب مخصص واضح
            'no_branch' => $rows->filter(fn ($row) => in_array('no_branch', $row['scopes'], true))->values(),
        ];
    }

    public static function classifyActiveOrder(string $status, ?string $orderType, int $ticketCount = 0): array
    {
        if (in_array($status, ['cancelled', 'canceled', 'served'], true)) {
            return [];
        }

        $scopes = ['operational_active'];
        if (in_array($status, ['pending', 'pending_confirmation', 'pending_payment'], true)) {
            $scopes[] = 'awaiting_payment';
        }
        if (in_array($status, ['confirmed', 'in_progress', 'ready'], true) || ($status === 'paid' && $ticketCount > 0)) {
            $scopes[] = 'kitchen_active';
        }
        if ($orderType === 'delivery' && in_array($status, ['paid', 'confirmed', 'in_progress', 'ready'], true)) {
            $scopes[] = 'delivery_active';
        }

        return $scopes;
    }

    /**
     * Search customers by phone, name, code, or mobile
     */
    public function searchCustomers(string $query, ?int $limit = 20): array
    {
        $customers = Customer::where(function (Builder $q) use ($query) {
            $q->where('phone', 'like', "%{$query}%")
                ->orWhere('mobile', 'like', "%{$query}%")
                ->orWhere('name', 'like', "%{$query}%")
                ->orWhere('code', 'like', "%{$query}%");
        })
            ->select('id', 'name', 'phone', 'mobile', 'code', 'status', 'category', 'city', 'address', 'branch_id', 'loyalty_points')
            ->with('branch:id,name')
            ->limit($limit)
            ->get()
            ->toArray();

        return $customers;
    }

    /**
     * Create a new customer (quick add from call center)
     * No GL account is provisioned ظ¤ only customer record created.
     */
    public function createCustomer(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $code = $data['code'] ?? null;
            if (!$code) {
                $code = 'C-' . strtoupper(uniqid());
            }

            $customer = $this->customerIdentity->create([
                'name' => $data['name'],
                'code' => $code,
                'phone' => $data['phone'] ?? null,
                'mobile' => $data['mobile'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'category' => $data['category'] ?? null,
                'notes' => $data['notes'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'status' => 'active',
            ]);

            // Create initial address if provided
            if (!empty($data['address']) || !empty($data['city'])) {
                $customer->addresses()->create([
                    'label' => $data['address_label'] ?? '┘à┘╪▓┘',
                    'city' => $data['city'] ?? null,
                    'area' => $data['area'] ?? null,
                    'district' => $data['district'] ?? null,
                    'street' => $data['street'] ?? null,
                    'landmark' => $data['landmark'] ?? null,
                    'building_no' => $data['building_no'] ?? null,
                    'floor' => $data['floor'] ?? null,
                    'apartment' => $data['apartment'] ?? null,
                    'delivery_notes' => $data['delivery_notes'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'is_default' => true,
                    'is_active' => true,
                ]);
            }

            // Create birthday occasion if provided
            if (!empty($data['birth_date'])) {
                $customer->occasions()->create([
                    'occasion_type' => 'birthday',
                    'title' => '╪╣┘è╪» ┘à┘è┘╪د╪» ' . $data['name'],
                    'date' => $data['birth_date'],
                    'repeats_annually' => true,
                    'is_active' => true,
                    'created_by' => $data['created_by'] ?? null,
                ]);
            }

            return $customer;
        });
    }

    /**
     * Get customer profile summary (for Quick Preview)
     */
    public function getCustomerProfile(int $customerId): array
    {
        $customer = Customer::with('branch:id,name')->findOrFail($customerId);

        $stats = $this->getCustomerOrderStats($customerId);
        $complaintsCount = CustomerComplaint::open()->where('customer_id', $customerId)->count();
        $latestNote = Order::where('customer_id', $customerId)
            ->whereNotNull('note')
            ->orderByDesc('created_at')
            ->value('note');

        return [
            'customer' => $customer->toArray(),
            'balance' => $customer->balance,
            'available_credit' => $customer->available_credit,
            'is_over_limit' => $customer->is_over_limit,
            'total_orders' => $stats['total_orders'],
            'monthly_orders_count' => $stats['monthly_orders_count'],
            'total_spent' => $stats['total_spent'],
            'avg_order_value' => $stats['avg_order_value'],
            'first_order_at' => $stats['first_order_at'],
            'last_order_at' => $stats['last_order_at'],
            'cancelled_orders_count' => $stats['cancelled_orders_count'],
            'open_complaints_count' => $complaintsCount,
            'latest_note' => $latestNote,
            'loyalty_points' => (int) ($customer->loyalty_points ?? 0),
        ];
    }

    /**
     * Aggregate the operational customer profile needed by the POS drawer.
     */
    public function getCustomerFullProfile(int $customerId): array
    {
        $orderIds = Order::where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->pluck('id');

        return [
            'profile' => $this->getCustomerProfile($customerId),
            'addresses' => $this->getCustomerAddresses($customerId),
            'orders' => $orderIds
                ->map(fn (int $orderId) => $this->getOrderDetails($orderId))
                ->values()
                ->all(),
            'permanent_notes' => CustomerNote::where('customer_id', $customerId)
                ->whereNull('order_id')
                ->orderByDesc('importance')
                ->orderByDesc('created_at')
                ->get()
                ->toArray(),
        ];
    }

    /**
     * Get customer orders paginated
     */
    public function getCustomerOrders(int $customerId, int $perPage = 20, ?string $cursor = null): array
    {
        $query = Order::where('customer_id', $customerId)
            ->with(['branch:id,name', 'cashier:id,name'])
            ->orderByDesc('created_at');

        if ($cursor) {
            $query->where('id', '<', $cursor);
        }

        $orders = $query->limit($perPage + 1)->get();

        $hasMore = $orders->count() > $perPage;
        $items = $hasMore ? $orders->slice(0, $perPage) : $orders;
        $nextCursor = $hasMore ? $items->last()->id : null;

        return [
            'data' => $items->map(fn($o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'status' => $o->status,
                'order_type' => $o->order_type,
                'total' => (float) $o->total,
                'subtotal' => (float) $o->subtotal,
                'discount_amount' => (float) $o->discount_amount,
                'discount_value' => (float) $o->discount_value,
                'note' => $o->note,
                'branch' => $o->branch ? ['id' => $o->branch->id, 'name' => $o->branch->name] : null,
                'cashier' => $o->cashier ? ['id' => $o->cashier->id, 'name' => $o->cashier->name] : null,
                'created_at' => $o->created_at,
                'customer_name' => $o->customer_name,
                'customer_phone' => $o->customer_phone,
                'delivery_fee' => (float) ($o->delivery_fee ?? 0),
                'delivery_address_snapshot' => $o->delivery_address_snapshot,
                'tax_rate' => (float) ($o->tax_rate ?? 0),
                'tax_amount' => (float) ($o->tax_amount ?? 0),
                'scheduled_at' => $o->scheduled_at,
            ])->values()->toArray(),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Get order details with items
     */
    public function getOrderDetails(int $orderId): array
    {
        $order = Order::with([
            'items',
            'branch:id,name',
            'cashier:id,name',
            'invoice',
            'feedback.recorder:id,name',
        ])->findOrFail($orderId);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'order_type' => $order->order_type,
            'source' => $order->source,
            'customer_id' => $order->customer_id,
            'customer_address_id' => $order->customer_address_id,
            'delivery_address_snapshot' => $order->delivery_address_snapshot,
            'subtotal' => (float) $order->subtotal,
            'discount_value' => (float) $order->discount_value,
            'discount_type' => $order->discount_type,
            'discount_amount' => (float) $order->discount_amount,
            'engine_discount_amount' => (float) $order->engine_discount_amount,
            'delivery_fee' => (float) ($order->delivery_fee ?? 0),
            'tax_rate' => (float) ($order->tax_rate ?? 0),
            'tax_amount' => (float) ($order->tax_amount ?? 0),
            'scheduled_at' => $order->scheduled_at,
            'total' => (float) $order->total,
            'note' => $order->note,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'branch' => $order->branch ? ['id' => $order->branch->id, 'name' => $order->branch->name] : null,
            'cashier' => $order->cashier ? ['id' => $order->cashier->id, 'name' => $order->cashier->name] : null,
            'created_at' => $order->created_at,
            'items' => $order->items->map(fn(OrderItem $i) => [
                'id' => $i->id,
                'item_id' => $i->item_id,
                'item_name' => $i->item_name,
                'item_name_ar' => $i->item_name_ar,
                'quantity' => (float) $i->quantity,
                'price' => (float) $i->price,
                'total' => (float) $i->total,
                'notes' => $i->notes,
            ])->toArray(),
            'invoice' => $order->invoice ? [
                'id' => $order->invoice->id,
                'number' => $order->invoice->number,
                'status' => $order->invoice->status,
            ] : null,
            'feedback' => $order->feedback,
        ];
    }

    /**
     * Get favorite items for a customer based on order history
     */
    public function getCustomerFavorites(int $customerId, int $limit = 20): array
    {
        // Note: order_items table doesn't have created_at column, so we use orders.created_at instead
        $favorites = OrderItem::select(
            'item_id',
            'item_name',
            'item_name_ar',
            DB::raw('SUM(quantity) as quantity_sum'),
            DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
            DB::raw('SUM(order_items.total) as total_spent'),
            DB::raw('MAX(orders.created_at) as last_ordered_at')
        )
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.customer_id', $customerId)
            ->whereNull('orders.deleted_at')
            ->groupBy('item_id', 'item_name', 'item_name_ar')
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->get()
            ->map(static function ($row) {
                $row->order_count = (int) $row->orders_count;
                $row->total_quantity = (float) $row->quantity_sum;
                $row->orders_count = (int) $row->orders_count;
                $row->quantity_sum = (float) $row->quantity_sum;
                $row->total_spent = (float) $row->total_spent;
                return $row;
            })
            ->toArray();

        return $favorites;
    }

    /**
     * Get customer addresses from CustomerAddress model
     */
    public function getCustomerAddresses(int $customerId): array
    {
        $query = CustomerAddress::where('customer_id', $customerId)
            ->where('is_active', true)
            ->orderByDesc('is_default');

        if (Schema::hasColumn('customer_addresses', 'last_used_at')) {
            $query->orderByDesc('last_used_at');
        }

        return $query->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    /**
     * Get customer complaints with pagination
     */
    public function getCustomerComplaints(int $customerId, int $perPage = 20): array
    {
        $complaints = CustomerComplaint::where('customer_id', $customerId)
            ->with(['assignedTo:id,name', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return $complaints->toArray();
    }

    /**
     * Unified chronological feed of a customer's calls, complaints, and orders.
     */
    public function getCustomerTimeline(int $customerId, int $limit = 30): array
    {
        $calls = CallTicket::where('customer_id', $customerId)
            ->with('agent:id,name')
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (CallTicket $ticket) => [
                'type' => 'call',
                'id' => $ticket->id,
                'occurred_at' => $ticket->started_at,
                'status' => $ticket->status,
                'call_type' => $ticket->call_type,
                'disposition' => $ticket->disposition,
                'duration_seconds' => $ticket->duration_seconds,
                'satisfaction_rating' => $ticket->satisfaction_rating,
                'agent' => $ticket->agent ? ['id' => $ticket->agent->id, 'name' => $ticket->agent->name] : null,
                'linked_order_id' => $ticket->linked_order_id,
            ]);

        $complaints = CustomerComplaint::where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (CustomerComplaint $complaint) => [
                'type' => 'complaint',
                'id' => $complaint->id,
                'occurred_at' => $complaint->created_at,
                'status' => $complaint->status,
                'title' => $complaint->title,
                'priority' => $complaint->priority,
            ]);

        $orders = Order::where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'order_number', 'status', 'order_type', 'total', 'created_at'])
            ->map(fn (Order $order) => [
                'type' => 'order',
                'id' => $order->id,
                'occurred_at' => $order->created_at,
                'status' => $order->status,
                'order_number' => $order->order_number,
                'order_type' => $order->order_type,
                'total' => (float) $order->total,
            ]);

        return $calls->concat($complaints)->concat($orders)
            ->sortByDesc(fn ($row) => $row['occurred_at'])
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * Real per-agent call-center performance, computed from call_tickets.
     */
    public function getAgentPerformance(?int $branchId = null, ?string $from = null, ?string $to = null): array
    {
        $from = $from ? Carbon::parse($from)->startOfDay() : now()->startOfDay();
        $to = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        $baseQuery = fn () => CallTicket::whereBetween('started_at', [$from, $to])
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId));

        $missedCallsTotal = (clone $baseQuery())->where('status', 'missed')->count();

        $agents = CallTicket::whereBetween('call_tickets.started_at', [$from, $to])
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->whereNotNull('agent_id')
            ->join('users', 'users.id', '=', 'call_tickets.agent_id')
            ->groupBy('call_tickets.agent_id', 'users.name')
            ->select(
                'call_tickets.agent_id',
                'users.name as agent_name',
                DB::raw('COUNT(*) as total_calls'),
                DB::raw("SUM(CASE WHEN call_tickets.status = 'completed' THEN 1 ELSE 0 END) as completed_calls"),
                DB::raw('AVG(CASE WHEN call_tickets.duration_seconds IS NOT NULL THEN call_tickets.duration_seconds ELSE NULL END) as avg_duration_seconds'),
                DB::raw("SUM(CASE WHEN call_tickets.call_type = 'complaint' THEN 1 ELSE 0 END) as complaint_calls"),
                DB::raw('AVG(call_tickets.satisfaction_rating) as avg_satisfaction'),
            )
            ->orderByDesc('total_calls')
            ->get()
            ->map(function ($row) {
                $totalCalls = (int) $row->total_calls;
                return [
                    'agent_id' => (int) $row->agent_id,
                    'agent_name' => $row->agent_name,
                    'total_calls' => $totalCalls,
                    'completed_calls' => (int) $row->completed_calls,
                    'average_handle_time_minutes' => $row->avg_duration_seconds !== null ? round($row->avg_duration_seconds / 60, 1) : null,
                    'complaint_calls' => (int) $row->complaint_calls,
                    'complaint_rate' => $totalCalls > 0 ? round(($row->complaint_calls / $totalCalls) * 100, 1) : 0.0,
                    'avg_satisfaction' => $row->avg_satisfaction !== null ? round((float) $row->avg_satisfaction, 2) : null,
                ];
            })
            ->all();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'missed_calls_total' => $missedCallsTotal,
            'agents' => $agents,
        ];
    }

    /**
     * لقطة شاملة لـ "لوحة العمليات" — كل الأرقام محسوبة من orders + call_tickets
     * الحقيقية (نفس المصادر المستخدمة في بقية الوحدة)، بدون بيانات وهمية.
     */
    public function getOperationsSnapshot(?int $branchId = null, ?int $agentId = null): array
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        $yesterdayStart = now()->copy()->subDay()->startOfDay();
        $yesterdayEnd = now()->copy()->subDay()->endOfDay();

        // مُقيَّد بـ source='call_center' ليطابق تماماً نفس مجموعة الطلبات التي تعرضها
        // "الطلبات النشطة" — قبل هذا كانت اللوحة تحسب كل طلبات المطعم (POS + كول سنتر)
        // بينما تلك الصفحة تعرض طلبات الكول سنتر فقط، فيظهر هنا رقم لا يقابله شيء هناك.
        $ordersTodayQuery = fn () => Order::where('orders.source', 'call_center')
            ->whereBetween('orders.created_at', [$todayStart, $todayEnd])
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId));

        $ordersTodayCount = (clone $ordersTodayQuery())->count();
        $salesToday = (float) (clone $ordersTodayQuery())->where('status', '!=', 'cancelled')->sum('total');

        $ordersYesterdayQuery = fn () => Order::where('orders.source', 'call_center')
            ->whereBetween('orders.created_at', [$yesterdayStart, $yesterdayEnd])
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId));
        $ordersYesterdayCount = (clone $ordersYesterdayQuery())->count();
        $salesYesterday = (float) (clone $ordersYesterdayQuery())->where('status', '!=', 'cancelled')->sum('total');

        $statusCounts = (clone $ordersTodayQuery())
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');
        $completed = (int) ($statusCounts['paid'] ?? 0);
        $cancelled = (int) ($statusCounts['cancelled'] ?? 0);
        $pendingBranch = (int) (($statusCounts['pending'] ?? 0) + ($statusCounts['pending_confirmation'] ?? 0));
        $preparing = max(0, $ordersTodayCount - $completed - $cancelled - $pendingBranch);

        $callsQuery = fn () => CallTicket::whereBetween('started_at', [$todayStart, $todayEnd])
            ->when($branchId, fn (Builder $q) => $q->where('branch_id', $branchId));
        $callsTotal = (clone $callsQuery())->count();
        $callsCompleted = (clone $callsQuery())->where('status', 'completed')->count();
        $callsMissed = (clone $callsQuery())->where('status', 'missed')->count();
        $callsConverted = (clone $callsQuery())->whereNotNull('linked_order_id')->count();
        $conversionRate = $callsTotal > 0 ? round(($callsConverted / $callsTotal) * 100, 1) : 0.0;

        $lastCall = (clone $callsQuery())->with('customer:id,name')->orderByDesc('started_at')->first();

        $ordersHourly = (clone $ordersTodayQuery())
            ->selectRaw('HOUR(orders.created_at) as hour, COUNT(*) as c')
            ->groupBy('hour')->pluck('c', 'hour');
        $callsHourly = (clone $callsQuery())
            ->selectRaw('HOUR(started_at) as hour, COUNT(*) as c')
            ->groupBy('hour')->pluck('c', 'hour');
        $currentHour = (int) now()->hour;
        $activeHours = collect($ordersHourly->keys())->merge($callsHourly->keys())->map(fn ($h) => (int) $h);
        $startHour = $activeHours->isNotEmpty() ? min($activeHours->min(), $currentHour) : $currentHour;
        $hourly = [];
        for ($h = $startHour; $h <= $currentHour; $h++) {
            $hourly[] = ['hour' => $h, 'orders' => (int) ($ordersHourly[$h] ?? 0), 'calls' => (int) ($callsHourly[$h] ?? 0)];
        }

        $topItems = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.source', 'call_center')
            ->whereBetween('orders.created_at', [$todayStart, $todayEnd])
            ->when($branchId, fn (Builder $q) => $q->where('orders.branch_id', $branchId))
            ->where('orders.status', '!=', 'cancelled')
            ->select('order_items.item_name_ar', 'order_items.item_name', DB::raw('SUM(order_items.quantity) as qty'))
            ->groupBy('order_items.item_name_ar', 'order_items.item_name')
            ->orderByDesc('qty')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['name' => $r->item_name_ar ?: $r->item_name, 'quantity' => (int) $r->qty])
            ->all();

        $branchDistribution = [];
        if (! $branchId) {
            $branchDistribution = Order::where('orders.source', 'call_center')
                ->whereBetween('orders.created_at', [$todayStart, $todayEnd])
                ->join('branches', 'branches.id', '=', 'orders.branch_id')
                ->select('branches.id', 'branches.name', DB::raw('COUNT(*) as c'))
                ->groupBy('branches.id', 'branches.name')
                ->orderByDesc('c')
                ->get()
                ->map(fn ($r) => ['branch_id' => (int) $r->id, 'branch_name' => $r->name, 'orders_count' => (int) $r->c])
                ->all();
        }

        $myPerformance = null;
        if ($agentId) {
            $myOrdersQuery = fn () => (clone $ordersTodayQuery())->where('call_center_agent_id', $agentId);
            $myOrders = (clone $myOrdersQuery())->count();
            $mySales = (float) (clone $myOrdersQuery())->where('status', '!=', 'cancelled')->sum('total');
            $myCallsQuery = fn () => CallTicket::whereBetween('started_at', [$todayStart, $todayEnd])->where('agent_id', $agentId);
            $myPerformance = [
                'orders' => $myOrders,
                'sales' => round($mySales, 2),
                'calls_total' => (clone $myCallsQuery())->count(),
                'calls_completed' => (clone $myCallsQuery())->where('status', 'completed')->count(),
            ];
        }

        return [
            'today' => [
                'orders_count' => $ordersTodayCount,
                'sales_total' => round($salesToday, 2),
                'avg_order_value' => $ordersTodayCount > 0 ? round($salesToday / $ordersTodayCount, 2) : 0.0,
                'calls_total' => $callsTotal,
                'calls_completed' => $callsCompleted,
                'calls_missed' => $callsMissed,
                'conversion_rate' => $conversionRate,
            ],
            'yesterday' => [
                'orders_count' => $ordersYesterdayCount,
                'sales_total' => round($salesYesterday, 2),
            ],
            'order_status_breakdown' => [
                'completed' => $completed,
                'preparing' => $preparing,
                'cancelled' => $cancelled,
                'pending_branch' => $pendingBranch,
            ],
            'hourly' => $hourly,
            'top_items' => $topItems,
            'branch_distribution' => $branchDistribution,
            'last_call' => $lastCall ? [
                'id' => $lastCall->id,
                'customer_name' => $lastCall->customer?->name,
                'phone' => $lastCall->incoming_phone,
                'started_at' => optional($lastCall->started_at)->toIso8601String(),
                'duration_seconds' => $lastCall->duration_seconds,
                'status' => $lastCall->status,
                'linked_order_id' => $lastCall->linked_order_id,
            ] : null,
            'my_performance' => $myPerformance,
        ];
    }

    /**
     * Get analytics for customer management dashboard
     */
    public function getDashboardAnalytics(): array
    {
        $now = now();
        $weekStart = $now->copy()->startOfWeek();
        $monthStart = $now->copy()->startOfMonth();

        $totalCustomers = Customer::count();
        $activeCustomers = Customer::where('status', 'active')->count();
        $newThisWeek = Customer::where('created_at', '>=', $weekStart)->count();
        $newThisMonth = Customer::where('created_at', '>=', $monthStart)->count();

        $orderedLast7Days = Order::where('created_at', '>=', $now->copy()->subDays(7))
            ->distinct('customer_id')
            ->count('customer_id');

        $orderedLast30Days = Order::where('created_at', '>=', $now->copy()->subDays(30))
            ->distinct('customer_id')
            ->count('customer_id');

        $openComplaints = CustomerComplaint::open()->count();

        $inactiveCustomers = Customer::whereNotIn('id', function ($q) {
            $q->from('orders')
                ->select('customer_id')
                ->where('created_at', '>=', now()->subDays(90))
                ->whereNotNull('customer_id');
        })->count();

        $avgOrderValue = Order::where('status', '!=', 'cancelled')
            ->avg('total') ?? 0;

        $totalOrderValue = Order::where('created_at', '>=', $monthStart)
            ->where('status', '!=', 'cancelled')
            ->sum('total') ?? 0;

        return [
            'total_customers' => $totalCustomers,
            'active_customers' => $activeCustomers,
            'new_this_week' => $newThisWeek,
            'new_this_month' => $newThisMonth,
            'ordered_last_7_days' => $orderedLast7Days,
            'ordered_last_30_days' => $orderedLast30Days,
            'open_complaints' => $openComplaints,
            'inactive_customers' => $inactiveCustomers,
            'avg_order_value' => round((float) $avgOrderValue, 2),
            'total_order_value_month' => round((float) $totalOrderValue, 2),
        ];
    }

    /**
     * Get top customers with filters
     */
    public function getTopCustomers(array $filters): array
    {
        $period = $filters['period'] ?? 'today';
        $sortBy = $filters['sort_by'] ?? 'total_spent';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $perPage = $filters['per_page'] ?? 25;
        $branchId = $filters['branch_id'] ?? null;

        [$from, $to] = $this->resolvePeriod($period, $filters);

        $query = Order::where('orders.created_at', '>=', $from)
            ->where('orders.created_at', '<=', $to)
            ->whereNotNull('orders.customer_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->leftJoin('customer_complaints', function ($join) {
                $join->on('customer_complaints.customer_id', '=', 'customers.id')
                    ->whereIn('customer_complaints.status', ['open', 'in_progress']);
            })
            ->select(
                'customers.id',
                'customers.name',
                'customers.phone',
                'customers.code',
                'customers.status',
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('SUM(CASE WHEN orders.status != "cancelled" THEN orders.total ELSE 0 END) as total_spent'),
                DB::raw('AVG(CASE WHEN orders.status != "cancelled" THEN orders.total ELSE NULL END) as avg_order_value'),
                DB::raw('COUNT(DISTINCT customer_complaints.id) as open_complaints_count'),
                DB::raw('MAX(orders.created_at) as last_order_at'),
                DB::raw('SUM(CASE WHEN orders.status = "cancelled" THEN 1 ELSE 0 END) as cancelled_count'),
            )
            ->groupBy('customers.id', 'customers.name', 'customers.phone', 'customers.code', 'customers.status');

        if ($branchId) {
            $query->where('orders.branch_id', $branchId);
        }

        // Apply sorting
        $sortColumn = match ($sortBy) {
            'orders_count' => 'orders_count',
            'total_spent' => 'total_spent',
            'avg_order_value' => 'avg_order_value',
            'cancelled_count' => 'cancelled_count',
            'open_complaints' => 'open_complaints_count',
            default => 'total_spent',
        };

        $query->orderBy($sortColumn, $sortDir);

        return $query->paginate($perPage)->toArray();
    }

    /**
     * Get all complaints (for complaints management page)
     */
    public function getAllComplaints(array $filters, int $perPage = 20): array
    {
        $query = CustomerComplaint::with([
            'customer:id,name,phone',
            'order:id,order_number',
            'assignedTo:id,name',
            'createdBy:id,name',
        ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (!empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('created_at');

        return $query->paginate($perPage)->toArray();
    }

    /**
     * Create a complaint
     */
    public function createComplaint(array $data, int $userId): CustomerComplaint
    {
        $complaint = CustomerComplaint::create([
            'customer_id' => $data['customer_id'],
            'order_id' => $data['order_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $userId,
            'title' => $data['title'],
            'subject' => $data['title'],
            'description' => $data['description'] ?? '',
            'type' => $data['type'] ?? 'other',
            'priority' => $data['priority'] ?? 'normal',
            'status' => CustomerComplaint::STATUS_NEW,
            'severity' => $data['severity'] ?? 'info',
            'is_sensitive' => $data['is_sensitive'] ?? false,
            'show_alert' => true,
            'branch_id' => $data['branch_id'] ?? null,
        ]);

        $this->addFollowup($complaint->id, $userId, 'created', '╪ز┘à ╪ح┘╪┤╪د╪ة ╪د┘╪┤┘â┘ê┘ë', 'system');

        return $complaint;
    }

    /**
     * Update complaint status
     */
    public function updateComplaintStatus(int $complaintId, string $newStatus, ?int $userId = null, ?string $notes = null): CustomerComplaint
    {
        $complaint = CustomerComplaint::findOrFail($complaintId);
        $oldStatus = $complaint->status;
        $complaint->status = $newStatus;

        if ($newStatus === CustomerComplaint::STATUS_RESOLVED) {
            $complaint->resolved_at = now();
        }

        if ($newStatus === CustomerComplaint::STATUS_CLOSED) {
            $complaint->closed_at = now();
        }

        if ($newStatus === CustomerComplaint::STATUS_RESOLVED || $newStatus === CustomerComplaint::STATUS_CLOSED) {
            $complaint->show_alert = false;
        }

        if ($newStatus === CustomerComplaint::STATUS_OPEN || $newStatus === CustomerComplaint::STATUS_IN_PROGRESS) {
            $complaint->show_alert = true;
        }

        $complaint->save();

        $this->addFollowup($complaintId, $userId, 'status_changed', $notes ?? "╪ز╪║┘è┘è╪▒ ╪د┘╪ص╪د┘╪ر ┘à┘ {$oldStatus} ╪ح┘┘ë {$newStatus}", 'system', $oldStatus, $newStatus);

        return $complaint;
    }

    /**
     * Add a followup to a complaint
     */
    public function addFollowup(int $complaintId, ?int $userId, string $action, string $notes, string $type = 'note', ?string $oldStatus = null, ?string $newStatus = null): ComplaintFollowup
    {
        return ComplaintFollowup::create([
            'complaint_id' => $complaintId,
            'user_id' => $userId,
            'action' => $action,
            'notes' => $notes,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'followup_type' => $type,
        ]);
    }

    /**
     * Get complaint timeline
     */
    public function getComplaintTimeline(int $complaintId): array
    {
        $complaint = CustomerComplaint::with(['customer:id,name,phone', 'order:id,order_number', 'assignedTo:id,name', 'followups.user:id,name'])->findOrFail($complaintId);

        return [
            'complaint' => $complaint->toArray(),
            'followups' => $complaint->followups->sortByDesc('created_at')->values()->toArray(),
        ];
    }

    /**
     * Get customer alerts (open complaints, recent sensitive resolved)
     */
    public function getCustomerAlerts(int $customerId): array
    {
        $alerts = [];

        $openComplaints = CustomerComplaint::where('customer_id', $customerId)
            ->where('show_alert', true)
            ->whereIn('status', [CustomerComplaint::STATUS_NEW, CustomerComplaint::STATUS_OPEN, CustomerComplaint::STATUS_IN_PROGRESS])
            ->with('order:id,order_number')
            ->get();

        foreach ($openComplaints as $complaint) {
            $days = $complaint->created_at->diffInDays(now());
            $orderRef = $complaint->order ? $complaint->order->order_number : null;

            $alerts[] = [
                'type' => 'open_complaint',
                'severity' => $complaint->priority === 'critical' ? 'critical' : ($complaint->priority === 'high' ? 'warning' : 'info'),
                'message' => $orderRef
                    ? "┘╪»┘ë ┘ç╪░╪د ╪د┘╪╣┘à┘è┘ ╪┤┘â┘ê┘ë ┘à┘╪ز┘ê╪ص╪ر ┘à┘╪░ {$days} ┘è┘ê┘à ╪ذ╪«╪╡┘ê╪╡ {$complaint->title} (╪د┘╪╖┘╪ذ {$orderRef})"
                    : "┘╪»┘ë ┘ç╪░╪د ╪د┘╪╣┘à┘è┘ ╪┤┘â┘ê┘ë ┘à┘╪ز┘ê╪ص╪ر ┘à┘╪░ {$days} ┘è┘ê┘à: {$complaint->title}",
                'complaint_id' => $complaint->id,
                'created_at' => $complaint->created_at,
            ];
        }

        $recentSensitive = CustomerComplaint::where('customer_id', $customerId)
            ->where('is_sensitive', true)
            ->where('status', CustomerComplaint::STATUS_RESOLVED)
            ->where('resolved_at', '>=', now()->subDays(3))
            ->get();

        foreach ($recentSensitive as $complaint) {
            $alerts[] = [
                'type' => 'recent_sensitive',
                'severity' => 'info',
                'message' => "┘ç╪░╪د ╪د┘╪╣┘à┘è┘ ┘ê╪د╪ش┘ç ┘à╪┤┘â┘╪ر ╪ص╪│╪د╪│╪ر ╪ز┘à ╪ص┘┘ç╪د ┘à╪ج╪«╪▒╪د┘ï. ┘è┘╪▒╪ش┘ë ╪ح╪╣╪╖╪د╪ة ╪د┘ç╪ز┘à╪د┘à ╪ح╪╢╪د┘┘è.",
                'complaint_id' => $complaint->id,
                'created_at' => $complaint->resolved_at,
            ];
        }

        return $alerts;
    }

    // ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤
    // ADDRESS MANAGEMENT
    // ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤

    public function createAddress(int $customerId, array $data): CustomerAddress
    {
        if (!empty($data['is_default'])) {
            CustomerAddress::where('customer_id', $customerId)->update(['is_default' => false]);
        }
        return CustomerAddress::create(array_merge($data, ['customer_id' => $customerId]));
    }

    public function updateAddress(int $addressId, array $data, int $customerId): CustomerAddress
    {
        if (!empty($data['is_default'])) {
            CustomerAddress::where('customer_id', $customerId)->where('id', '!=', $addressId)->update(['is_default' => false]);
        }
        $address = CustomerAddress::findOrFail($addressId);
        $address->update($data);
        return $address;
    }

    public function markAddressUsed(int $addressId): void
    {
        if (Schema::hasColumn('customer_addresses', 'last_used_at')) {
            CustomerAddress::where('id', $addressId)->update(['last_used_at' => now()]);
        }
    }

    // ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤
    // OCCASION MANAGEMENT
    // ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤

    public function getCustomerOccasions(int $customerId): array
    {
        return CustomerOccasion::where('customer_id', $customerId)
            ->where('is_active', true)
            ->orderBy('date')
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    public function createOccasion(int $customerId, array $data, ?int $userId = null): CustomerOccasion
    {
        return CustomerOccasion::create(array_merge($data, [
            'customer_id' => $customerId,
            'created_by' => $userId,
        ]));
    }

    public function updateOccasion(int $occasionId, array $data): CustomerOccasion
    {
        $occasion = CustomerOccasion::findOrFail($occasionId);
        $occasion->update($data);
        return $occasion;
    }

    public function deleteOccasion(int $occasionId): void
    {
        CustomerOccasion::where('id', $occasionId)->delete();
    }

    /**
     * Get occasions by date range
     */
    public function getOccasionsByRange(string $range, ?int $customerId = null): array
    {
        $now = now();
        $query = CustomerOccasion::where('is_active', true)
            ->with('customer:id,name,phone,mobile')
            ->orderBy('date');

        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        $occasions = match ($range) {
            'today' => $query->whereMonth('date', $now->month)
                ->whereDay('date', $now->day)
                ->get(),
            'tomorrow' => $query->whereMonth('date', $now->copy()->addDay()->month)
                ->whereDay('date', $now->copy()->addDay()->day)
                ->get(),
            'week' => $query->whereBetween('date', [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()])
                ->get()
                ->filter(fn($o) => $o->date->between($now->copy()->startOfWeek(), $now->copy()->endOfWeek()))
                ->values(),
            'month' => $query->whereMonth('date', $now->month)
                ->whereYear('date', $now->year)
                ->get(),
            'upcoming' => $query->where('date', '>=', $now)
                ->limit(50)
                ->get(),
            'past' => $query->where('date', '<', $now)
                ->limit(50)
                ->get(),
            default => $query->limit(50)->get(),
        };

        return $occasions->toArray();
    }

    // ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤
    // NOTE MANAGEMENT
    // ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤ظ¤

    public function getCustomerNotes(int $customerId, bool $includeSensitive = false): array
    {
        return CustomerNote::where('customer_id', $customerId)
            ->when(! $includeSensitive, fn ($query) => $query->where('type', '!=', 'sensitive'))
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    public function createNote(int $customerId, array $data, ?int $userId = null): CustomerNote
    {
        return CustomerNote::create(array_merge($data, [
            'customer_id' => $customerId,
            'created_by' => $userId,
        ]));
    }

    public function getImportantNotes(int $customerId, bool $includeSensitive = false): array
    {
        return CustomerNote::where('customer_id', $customerId)
            ->when(! $includeSensitive, fn ($query) => $query->where('type', '!=', 'sensitive'))
            ->where(function ($q) {
                $q->where('show_during_order', true)
                    ->orWhere('importance', 'high')
                    ->orWhere('importance', 'urgent');
            })
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->toArray();
    }

    private function getCustomerOrderStats(int $customerId): array
    {
        $stats = Order::where('customer_id', $customerId)
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('SUM(CASE WHEN status != "cancelled" THEN total ELSE 0 END) as total_spent')
            ->selectRaw('AVG(CASE WHEN status != "cancelled" THEN total ELSE NULL END) as avg_order_value')
            ->selectRaw('MIN(created_at) as first_order_at')
            ->selectRaw('MAX(created_at) as last_order_at')
            ->selectRaw('SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled_orders_count')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as monthly_orders_count', [now()->startOfMonth()])
            ->first();

        return [
            'total_orders' => (int) ($stats->total_orders ?? 0),
            'total_spent' => (float) ($stats->total_spent ?? 0),
            'avg_order_value' => (float) ($stats->avg_order_value ?? 0),
            'first_order_at' => $stats->first_order_at,
            'last_order_at' => $stats->last_order_at,
            'cancelled_orders_count' => (int) ($stats->cancelled_orders_count ?? 0),
            'monthly_orders_count' => (int) ($stats->monthly_orders_count ?? 0),
        ];
    }

    private function resolvePeriod(string $period, array $filters): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'last_7_days' => [now()->subDays(7)->startOfDay(), now()->endOfDay()],
            'last_30_days' => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            'custom' => [
                Carbon::parse($filters['from'] ?? now()->startOfMonth())->startOfDay(),
                Carbon::parse($filters['to'] ?? now())->endOfDay(),
            ],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }
}
