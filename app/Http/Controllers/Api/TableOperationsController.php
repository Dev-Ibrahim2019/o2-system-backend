<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\DiningZone;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TableOperationsController extends Controller
{
    /**
     * عرض جميع القاعات والطاولات بناءً على الفرع (للكاشير/الضيافة/المحاسب/المدير)
     * GET /api/tables?branch_id=1
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $request->input('branch_id', $user->branch_id);

        if (!$user->hasRole('super-admin') && $user->branch_id != $branchId) {
            return response()->json([
                'success' => false,
                'message' => 'لا تملك صلاحية الوصول لبيانات هذا الفرع!'
            ], 403);
        }

        $zones = DiningZone::with(['tables' => function ($query) {
            $query->with(['currentOrder' => function ($q) {
                $q->select('id', 'order_number', 'status', 'total', 'customer_name', 'dining_table_id');
            }])
            ->with('mergedWith:id,table_number')
            ->orderByRaw("CAST(SUBSTRING(table_number FROM '[0-9]+$') AS INTEGER)");
        }])
        ->where('branch_id', $branchId)
        ->where('status', 'ACTIVE')
        ->get();

        $tableIds = $zones->flatMap->tables->pluck('id')->toArray();
        $tableNumbers = $zones->flatMap->tables->pluck('table_number')->unique()->toArray();
        $allOrders = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where(function ($q) use ($tableIds, $tableNumbers) {
                $q->whereIn('dining_table_id', $tableIds)
                  ->orWhereIn('table_number', $tableNumbers);
            })
            ->whereIn('status', ['pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready'])
            ->with('items')
            ->select('id', 'order_number', 'status', 'total', 'customer_name', 'dining_table_id', 'table_number')
            ->get()
            ->groupBy('table_number');

        $formattedZones = $zones->map(function ($zone) use ($allOrders) {
            return [
                'id' => (string) $zone->id,
                'name' => $zone->name,
                'code' => $zone->code,
                'tables' => $zone->tables->map(function ($table) use ($allOrders) {
                    $order = $table->currentOrder;
                    $mergedWith = $table->mergedWith;
                    $tableOrders = $allOrders->get($table->table_number, collect())->map(fn($o) => [
                        'id' => (string) $o->id,
                        'order_number' => $o->order_number,
                        'status' => $o->status,
                        'total' => (float) $o->total,
                        'customer_name' => $o->customer_name,
                        'items' => $o->items->map(fn($item) => [
                            'id' => $item->id,
                            'item_name' => $item->item_name,
                            'item_name_ar' => $item->item_name_ar ?? $item->item_name,
                            'quantity' => $item->quantity,
                            'unit_price' => (float) $item->unit_price,
                            'total_price' => (float) $item->total_price,
                        ])->values()->all(),
                    ])->values()->all();
                    return [
                        'id' => (string) $table->id,
                        'table_number' => $table->table_number,
                        'number' => (int) preg_replace('/[^0-9]/', '', $table->table_number),
                        'capacity' => $table->capacity,
                        'status' => $table->status,
                        'seated_at' => $table->seated_at?->toIso8601String(),
                        'customer_count' => $table->customer_count,
                        'current_order_id' => $table->current_order_id ? (string) $table->current_order_id : null,
                        'merged_with_id' => $table->merged_with_id ? (string) $table->merged_with_id : null,
                        'merged_with_table_number' => $mergedWith?->table_number,
                        'merge_info' => $table->status === 'MERGED' && $mergedWith ? [
                            'is_merged' => true,
                            'merged_with_id' => (string) $mergedWith->id,
                            'merged_with_table_number' => $mergedWith->table_number,
                            'status_text' => 'مدمجة مع ' . $mergedWith->table_number,
                            'status_color' => '#f59e0b',
                            'status_icon' => 'merge',
                        ] : null,
                        'orders' => $tableOrders,
                        'current_order' => $order ? [
                            'id' => (string) $order->id,
                            'order_number' => $order->order_number,
                            'status' => $order->status,
                            'total' => (float) $order->total,
                            'customer_name' => $order->customer_name,
                        ] : null,
                    ];
                }),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedZones,
        ]);
    }

    public function seat(Request $request, DiningTable $table): JsonResponse
    {
        $request->validate([
            'customer_count' => 'nullable|integer|min:1|max:50',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
        ]);

        if ($table->status === 'MERGED') {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة مدمجة مع طاولة ' . ($table->mergedWith?->table_number ?? '') . ' - لا يمكن تنفيذ عمليات عليها!'
            ], 422);
        }

        if ($table->status !== 'AVAILABLE' && $table->status !== 'RESERVED') {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة غير متاحة للتسكين (الحالة الحالية: ' . $table->status . ')'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $user = $request->user();

            $table->setOccupied(null, $request->input('customer_count', 1));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'تم تسكين الطاولة ' . $table->table_number . ' بنجاح',
                'data' => [
                    'table' => $this->formatTable($table->fresh()),
                ]
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'فشل تسكين الطاولة: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateStatus(Request $request, DiningTable $table): JsonResponse
    {
        if ($table->status === 'MERGED') {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة مدمجة مع طاولة ' . ($table->mergedWith?->table_number ?? '') . ' - لا يمكن تغيير حالتها!'
            ], 422);
        }

        $request->validate([
            'status' => 'required|in:AVAILABLE,OCCUPIED,PAYMENT_PENDING,PAID,RESERVED,CLEANING,MERGED',
            'current_order_id' => 'nullable|integer',
            'customer_count' => 'nullable|integer',
        ]);

        $updateData = ['status' => $request->status];

        if ($request->status === 'AVAILABLE') {
            $updateData['current_order_id'] = null;
            $updateData['seated_at'] = null;
            $updateData['customer_count'] = 0;
            $updateData['last_order_at'] = now();
        } elseif ($request->status === 'OCCUPIED') {
            if ($request->has('current_order_id')) {
                $updateData['current_order_id'] = $request->input('current_order_id');
            }
            if ($request->has('customer_count')) {
                $updateData['customer_count'] = $request->input('customer_count');
            }
            $updateData['last_order_at'] = now();
        }

        $table->update($updateData);

        return response()->json([
            'success' => true,
            'data' => $this->formatTable($table->fresh()),
        ]);
    }

    public function free(DiningTable $table): JsonResponse
    {
        if ($table->status === 'MERGED') {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة مدمجة مع طاولة ' . ($table->mergedWith?->table_number ?? '') . ' - لا يمكن تحريرها!'
            ], 422);
        }

        \Log::info('[TABLE FREE] Called for table', [
            'id' => $table->id,
            'table_number' => $table->table_number,
            'status_before' => $table->status,
            'current_order_id' => $table->current_order_id,
        ]);

        $table->setAvailable();

        $fresh = $table->fresh();

        \Log::info('[TABLE FREE] After setAvailable', [
            'id' => $fresh->id,
            'status_after' => $fresh->status,
            'seated_at' => $fresh->seated_at,
            'current_order_id' => $fresh->current_order_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تحرير الطاولة ' . $table->table_number,
            'data' => $this->formatTable($fresh),
        ]);
    }

    public function show(DiningTable $table): JsonResponse
    {
        $table->load(['currentOrder', 'zone.branch', 'mergedWith:id,table_number']);

        $activeOrders = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where(function ($q) use ($table) {
                $q->where('dining_table_id', $table->id)
                  ->orWhere('table_number', $table->table_number);
            })
            ->whereIn('status', ['pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready'])
            ->select('id', 'order_number', 'status', 'total', 'customer_name', 'dining_table_id', 'table_number')
            ->get();

        $data = $this->formatTable($table);
        $data['orders'] = $activeOrders;

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function transfer(Request $request): JsonResponse
    {
        $request->validate([
            'from_table_id' => 'required|exists:dining_tables,id',
            'to_table_id' => 'required|exists:dining_tables,id|different:from_table_id',
        ]);

        $fromTable = DiningTable::findOrFail($request->from_table_id);
        $toTable = DiningTable::findOrFail($request->to_table_id);

        if (!$fromTable->current_order_id) {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة المصدر لا تحتوي على طلب نشط!'
            ], 422);
        }

        if ($toTable->status !== 'AVAILABLE') {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة الهدف غير متاحة!'
            ], 422);
        }

        DB::transaction(function () use ($fromTable, $toTable) {
            $orderId = $fromTable->current_order_id;
            Order::where('id', $orderId)->update(['dining_table_id' => $toTable->id, 'table_number' => $toTable->table_number]);
            $fromTable->setAvailable();
            $toTable->setOccupied($orderId);
        });

        return response()->json([
            'success' => true,
            'message' => 'تم تحويل الطلب من ' . $fromTable->table_number . ' إلى ' . $toTable->table_number,
        ]);
    }

    public function merge(Request $request): JsonResponse
    {
        $request->validate([
            'from_table_ids' => 'required|array|min:1',
            'from_table_ids.*' => 'exists:dining_tables,id',
            'to_table_id' => 'required|exists:dining_tables,id',
        ]);

        $fromTableIds = $request->input('from_table_ids');
        $toTableId = $request->input('to_table_id');

        if (in_array($toTableId, $fromTableIds)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن دمج الطاولة مع نفسها!'
            ], 422);
        }

        $toTable = DiningTable::findOrFail($toTableId);
        $fromTables = DiningTable::whereIn('id', $fromTableIds)->get();

        if ($fromTables->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'لا توجد طاولات مصدر صالحة!'
            ], 422);
        }

        if ($toTable->status === 'MERGED') {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة الهدف مدمجة مع طاولة ' . ($toTable->mergedWith?->table_number ?? '') . '!'
            ], 422);
        }

        $alreadyMerged = $fromTables->filter(fn($t) => $t->status === 'MERGED');
        if ($alreadyMerged->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'الطاولات التالية مدمجة مسبقاً: ' . $alreadyMerged->pluck('table_number')->implode(', ')
            ], 422);
        }

        DB::beginTransaction();
        try {
            $mergedCount = 0;
            $totalCustomerCount = $toTable->customer_count ?? 0;
            $lastOrderId = $toTable->current_order_id;

            foreach ($fromTables as $fromTable) {
                $activeOrders = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where(function ($q) use ($fromTable) {
                        $q->where('dining_table_id', $fromTable->id)
                          ->orWhere('table_number', $fromTable->table_number);
                    })
                    ->whereIn('status', ['pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready'])
                    ->get();

                foreach ($activeOrders as $order) {
                    $order->update([
                        'dining_table_id' => $toTable->id,
                        'table_number' => $toTable->table_number,
                    ]);
                    $lastOrderId = $order->id;
                }

                $totalCustomerCount += $fromTable->customer_count ?? 0;

                $fromTable->setMerged($toTable->id);

                $mergedCount++;
            }

            $toTable->update([
                'status' => 'OCCUPIED',
                'current_order_id' => $lastOrderId,
                'customer_count' => $totalCustomerCount,
                'seated_at' => $toTable->seated_at ?? now(),
                'last_order_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "تم دمج {$mergedCount} طاولة بنجاح مع طاولة {$toTable->table_number}",
                'data' => [
                    'merged_count' => $mergedCount,
                    'target_table' => $toTable->table_number,
                    'total_customer_count' => $totalCustomerCount,
                ]
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'فشل دمج الطاولات: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * فك دمج الطاولة - إرجاعها إلى حالة متاحة
     * POST /api/tables/{table}/unmerge
     */
    public function unmerge(DiningTable $table): JsonResponse
    {
        if (!$table->isMerged()) {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة غير مدمجة!'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $table->setUnmerged();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'تم فك دمج الطاولة ' . $table->table_number . ' بنجاح',
                'data' => $this->formatTable($table->fresh()),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'فشل فك الدمج: ' . $e->getMessage()
            ], 500);
        }
    }

    private function formatTable(DiningTable $table): array
    {
        $order = $table->currentOrder;
        $mergedWith = $table->mergedWith;

        $result = [
            'id' => (string) $table->id,
            'dining_zone_id' => (string) $table->dining_zone_id,
            'table_number' => $table->table_number,
            'number' => (int) preg_replace('/[^0-9]/', '', $table->table_number),
            'capacity' => $table->capacity,
            'status' => $table->status,
            'seated_at' => $table->seated_at?->toIso8601String(),
            'customer_count' => $table->customer_count,
            'current_order_id' => $table->current_order_id ? (string) $table->current_order_id : null,
            'merged_with_id' => $table->merged_with_id ? (string) $table->merged_with_id : null,
            'merged_with_table_number' => $mergedWith?->table_number,
            'merge_info' => $table->status === 'MERGED' && $mergedWith ? [
                'is_merged' => true,
                'merged_with_id' => (string) $mergedWith->id,
                'merged_with_table_number' => $mergedWith->table_number,
                'status_text' => 'مدمجة مع ' . $mergedWith->table_number,
                'status_color' => '#f59e0b',
                'status_icon' => 'merge',
                'hint' => 'لا يمكن تنفيذ أي عمليات على هذه الطاولة',
            ] : null,
            'current_order' => $order ? [
                'id' => (string) $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total' => (float) $order->total,
                'customer_name' => $order->customer_name,
            ] : null,
        ];

        return $result;
    }

    /**
     * تأجيل جميع الطلبات النشطة لطاولة كفاتورة واحدة
     * POST /api/tables/{table}/defer-all
     */
    public function deferAll(Request $request, DiningTable $table): JsonResponse
    {
        if ($table->status === 'MERGED') {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة مدمجة - لا يمكن تأجيل الطلبات منها!'
            ], 422);
        }

        try {
            $orders = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where(function ($q) use ($table) {
                    $q->where('dining_table_id', $table->id)
                      ->orWhere('table_number', $table->table_number);
                })
                ->whereIn('status', ['pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready'])
                ->with('items')
                ->get();

            if ($orders->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'لا توجد طلبات نشطة على هذه الطاولة'
                ], 422);
            }

            // إذا كان في طلب واحد بس، نأجّله عادي
            if ($orders->count() === 1) {
                $order = $orders->first();
                $deferredTableNote = $order->table_number ? "[Table: {$order->table_number}]" : "";
                $existingNote = $order->note ?? "";
                $newNote = $deferredTableNote . ($existingNote && $deferredTableNote ? " | " . $existingNote : ($existingNote ?: $deferredTableNote));

                $order->update([
                    'status' => 'pending_payment',
                    'dining_table_id' => null,
                    'table_number' => null,
                    'note' => $newNote,
                ]);

                $table->setAvailable();

                return response()->json([
                    'success' => true,
                    'message' => 'تم تأجيل الطلب بنجاح',
                    'data' => ['order_id' => $order->id]
                ]);
            }

            // دمج جميع الطلبات في طلب واحد
            DB::beginTransaction();

            // حساب الإجماليات
            $totalSubtotal = 0;
            $totalDiscount = 0;
            $allItems = [];
            $tableNumbers = [];
            $notes = [];
            $customerName = null;
            $customerPhone = null;
            $firstOrder = $orders->first();

            foreach ($orders as $order) {
                $totalSubtotal += $order->subtotal ?? 0;
                $totalDiscount += $order->discount_value ?? 0;

                if ($order->customer_name && !$customerName) {
                    $customerName = $order->customer_name;
                }
                if ($order->customer_phone && !$customerPhone) {
                    $customerPhone = $order->customer_phone;
                }

                if ($order->table_number && !in_array($order->table_number, $tableNumbers)) {
                    $tableNumbers[] = $order->table_number;
                }

                if ($order->note && strpos($order->note, '[Table:') === false) {
                    $notes[] = $order->note;
                }

                foreach ($order->items as $item) {
                    $allItems[] = $item;
                }
            }

            // إنشاء طلب جديد مدمج
            $mergedOrder = Order::create([
                'order_number' => 'DEF-' . strtoupper(uniqid()),
                'status' => 'pending_payment',
                'order_type' => $firstOrder->order_type,
                'subtotal' => $totalSubtotal,
                'total' => $totalSubtotal - $totalDiscount,
                'discount_value' => $totalDiscount,
                'discount_type' => $firstOrder->discount_type,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'branch_id' => $table->branch_id,
                'user_id' => $request->user()->id,
                'note' => '[Table: ' . implode(', ', $tableNumbers) . ']' . ($notes ? ' | ' . implode(' | ', $notes) : ''),
            ]);

            // نسخ العناصر إلى الطلب المدمج
            foreach ($allItems as $item) {
                $item->update([
                    'order_id' => $mergedOrder->id,
                ]);
            }

            // إلغاء الطلبات القديمة
            foreach ($orders as $order) {
                $order->update([
                    'status' => 'cancelled',
                    'note' => '[MERGED_TO: ' . $mergedOrder->order_number . ']',
                ]);
            }

            // تحرير الطاولة
            $table->setAvailable();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'تم تأجيل ' . $orders->count() . ' طلبات كفاتورة واحدة',
                'data' => [
                    'order_id' => $mergedOrder->id,
                    'orders_merged' => $orders->count(),
                ]
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'فشل تأجيل الطلبات: ' . $e->getMessage()
            ], 500);
        }
    }
}
