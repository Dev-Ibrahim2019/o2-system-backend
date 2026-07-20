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
            ->orderByRaw("CAST(SUBSTRING(table_number FROM '[0-9]+$') AS INTEGER)");
        }])
        ->where('branch_id', $branchId)
        ->where('status', 'ACTIVE')
        ->get()
        ->map(function ($zone) {
            return [
                'id' => (string) $zone->id,
                'name' => $zone->name,
                'code' => $zone->code,
                'tables' => $zone->tables->map(function ($table) {
                    $order = $table->currentOrder;
                    return [
                        'id' => (string) $table->id,
                        'table_number' => $table->table_number,
                        'number' => (int) preg_replace('/[^0-9]/', '', $table->table_number),
                        'capacity' => $table->capacity,
                        'status' => $table->status,
                        'seated_at' => $table->seated_at?->toIso8601String(),
                        'customer_count' => $table->customer_count,
                        'current_order_id' => $table->current_order_id ? (string) $table->current_order_id : null,
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
            'data' => $zones,
        ]);
    }

    public function seat(Request $request, DiningTable $table): JsonResponse
    {
        $request->validate([
            'customer_count' => 'nullable|integer|min:1|max:50',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
        ]);

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
        $request->validate([
            'status' => 'required|in:AVAILABLE,OCCUPIED,PAYMENT_PENDING,PAID,RESERVED,CLEANING',
        ]);

        $table->update(['status' => $request->status]);

        if ($request->status === 'AVAILABLE') {
            $table->update([
                'current_order_id' => null,
                'seated_at' => null,
                'customer_count' => 0,
                'last_order_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatTable($table->fresh()),
        ]);
    }

    public function free(DiningTable $table): JsonResponse
    {
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
        return response()->json([
            'success' => true,
            'data' => $this->formatTable($table->load(['currentOrder', 'zone.branch'])),
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

    private function formatTable(DiningTable $table): array
    {
        $order = $table->currentOrder;
        return [
            'id' => (string) $table->id,
            'dining_zone_id' => (string) $table->dining_zone_id,
            'table_number' => $table->table_number,
            'number' => (int) preg_replace('/[^0-9]/', '', $table->table_number),
            'capacity' => $table->capacity,
            'status' => $table->status,
            'seated_at' => $table->seated_at?->toIso8601String(),
            'customer_count' => $table->customer_count,
            'current_order_id' => $table->current_order_id ? (string) $table->current_order_id : null,
            'current_order' => $order ? [
                'id' => (string) $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total' => (float) $order->total,
                'customer_name' => $order->customer_name,
            ] : null,
        ];
    }
}
