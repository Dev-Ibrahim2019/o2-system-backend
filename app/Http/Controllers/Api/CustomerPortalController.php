<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\DiningZone;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log as LogFacade;

class CustomerPortalController extends Controller
{
    /**
     * بوابة الدخول للزبون عبر مسح QR Code
     * GET /api/customer/table/{qrCode}
     */
    public function lookupByQrCode(string $qrCode): JsonResponse
    {
        $table = DiningTable::where('qr_code', $qrCode)
            ->with(['zone.branch'])
            ->first();

        if (!$table) {
            return response()->json([
                'success' => false,
                'message' => 'رمز QR غير صالح أو الطاولة غير موجودة'
            ], 404);
        }

        if ($table->status !== 'AVAILABLE' && $table->status !== 'OCCUPIED' && $table->status !== 'HAS_ORDER' && $table->status !== 'PENDING_CONFIRMATION') {
            return response()->json([
                'success' => false,
                'message' => 'الطاولة غير متاحة حالياً'
            ], 422);
        }

        $branch = $table->zone?->branch;
        $branchId = $branch?->id;

        $items = Item::with('department')
            ->where('is_active', true)
            ->when($branchId, function ($q) use ($branchId) {
                $q->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branches.id', $branchId)
                      ->where('branch_item.is_active', true);
                });
            })
            ->get();

        $categories = $items
            ->groupBy(function ($item) {
                return $item->department?->name ?? 'عام';
            })
            ->map(function ($items, $categoryName) use ($branchId) {
                $first = $items->first();
                return [
                    'id' => $first?->department_id ?? 0,
                    'name' => $categoryName,
                    'name_ar' => $categoryName,
                    'icon' => $first?->department?->icon ?? 'Utensils',
                    'color' => $first?->department?->color ?? '#6b7280',
                    'type' => $first?->department?->type ?? 'MAIN_KITCHEN',
                    'items' => $items->map(function ($item) use ($branchId) {
                        return [
                            'id' => $item->id,
                            'name' => $item->name,
                            'name_ar' => $item->name_ar ?? $item->name,
                            'code' => $item->code ?? '',
                            'image' => $item->image_url ?? $item->image,
                            'unit' => $item->unit ?? '',
                            'price' => (float) ($branchId ? ($item->priceForBranch($branchId) ?? 0) : 0),
                            'department_id' => $item->department_id,
                        ];
                    })->values(),
                ];
            })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'table' => [
                    'id' => (string) $table->id,
                    'table_number' => $table->table_number,
                    'status' => $table->status,
                    'capacity' => $table->capacity,
                    'hall_name' => $table->zone?->name ?? '',
                    'hall_id' => (string) $table->dining_zone_id,
                    'branch_id' => $branch?->id ?? 0,
                    'branch_name' => $branch?->name ?? '',
                ],
                'menu' => [
                    'categories' => $categories,
                    'total_items' => $categories->sum(fn($c) => count($c['items'])),
                ],
                'restaurant' => [
                    'name' => $branch?->name ?? 'المطعم',
                    'tagline' => '',
                    'currency' => '₪',
                    'discount_rate' => 0,
                ],
            ]
        ]);
    }

    /**
     * جلب الطلب النشط للطاولة
     * GET /api/customer/table/{qrCode}/active-order
     */
    public function activeOrder(string $qrCode): JsonResponse
    {
        $table = DiningTable::where('qr_code', $qrCode)->first();

        if (!$table) {
            return response()->json([
                'success' => false,
                'message' => 'رمز QR غير صالح'
            ], 404);
        }

        $order = Order::with(['items' => function ($q) {
            $q->with('item');
        }])
        ->where('dining_table_id', $table->id)
        ->whereNotIn('status', ['paid', 'cancelled', 'served'])
        ->latest()
        ->first();

        if (!$order) {
            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'لا يوجد طلب نشط'
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'subtotal' => (float) $order->subtotal,
                'discount_value' => (float) ($order->discount ?? 0),
                'discount_amount' => (float) ($order->discount_amount ?? 0),
                'total' => (float) $order->total,
                'items' => $order->items->map(fn($item) => [
                    'item_id' => $item->item_id,
                    'item_name' => $item->item?->name ?? '',
                    'item_name_ar' => $item->item?->name_ar ?? $item->item?->name ?? '',
                    'quantity' => $item->quantity,
                    'price' => (float) $item->price,
                    'total' => (float) ($item->quantity * $item->price),
                    'notes' => $item->notes,
                ]),
                'created_at' => $order->created_at->toIso8601String(),
            ]
        ]);
    }

    /**
     * عرض قائمة الطعام للفرع
     * GET /api/customer/menu/{branchId}
     */
    public function menu(int $branchId): JsonResponse
    {
        $branch = Branch::find($branchId);
        if (!$branch) {
            return response()->json([
                'success' => false,
                'message' => 'الفرع غير موجود'
            ], 404);
        }

        $items = Item::with('department')
            ->where('is_active', true)
            ->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branches.id', $branchId)
                  ->where('branch_item.is_active', true);
            })
            ->get();

        $categories = $items
            ->groupBy(function ($item) {
                return $item->department?->name ?? 'عام';
            })
            ->map(function ($items, $categoryName) use ($branchId) {
                $first = $items->first();
                return [
                    'id' => $first?->department_id ?? 0,
                    'name' => $categoryName,
                    'name_ar' => $categoryName,
                    'icon' => $first?->department?->icon ?? 'Utensils',
                    'color' => $first?->department?->color ?? '#6b7280',
                    'type' => $first?->department?->type ?? 'MAIN_KITCHEN',
                    'items' => $items->map(function ($item) use ($branchId) {
                        return [
                            'id' => $item->id,
                            'name' => $item->name,
                            'name_ar' => $item->name_ar ?? $item->name,
                            'code' => $item->code ?? '',
                            'image' => $item->image_url ?? $item->image,
                            'unit' => $item->unit ?? '',
                            'price' => (float) ($item->priceForBranch($branchId) ?? 0),
                            'department_id' => $item->department_id,
                        ];
                    })->values(),
                ];
            })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categories,
                'total_items' => $categories->sum(fn($c) => count($c['items'])),
            ]
        ]);
    }

    /**
     * إضافة طلب فرعي (من الزبون)
     * POST /api/customer/add-sub-order
     */
    public function addSubOrder(Request $request): JsonResponse
    {
        LogFacade::info('Customer order request', $request->all());

        try {
            $request->validate([
                'qr_code' => 'required|string|exists:dining_tables,qr_code',
                'items' => 'required|array|min:1',
                'items.*.item_id' => 'required|integer|exists:items,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.notes' => 'nullable|string|max:255',
            ]);

            $table = DiningTable::where('qr_code', $request->qr_code)->firstOrFail();
        } catch (\Exception $e) {
            LogFacade::error('Validation error', ['error' => $e->getMessage(), 'data' => $request->all()]);
            return response()->json([
                'success' => false,
                'message' => 'بيانات غير صالحة: ' . $e->getMessage(),
                'errors' => $e instanceof \Illuminate\Validation\ValidationException ? $e->errors() : null,
            ], 422);
        }

        try {
            // البحث عن طلب نشط أو إنشاء واحد جديد
            $order = Order::where('dining_table_id', $table->id)
                ->whereNotIn('status', ['paid', 'cancelled', 'served'])
                ->latest()
                ->first();

            if (!$order) {
                $order = Order::create([
                    'dining_table_id' => $table->id,
                    'branch_id' => $table->zone?->branch_id,
                    'order_number' => 'ORD-' . strtoupper(uniqid()),
                    'order_type' => 'dine_in',
                    'status' => 'pending',
                    'subtotal' => 0,
                    'total' => 0,
                    'customer_name' => $request->input('customer_name', 'زبون'),
                    'table_number' => $table->table_number,
                ]);

                $table->setPendingConfirmation();
            }

            $subtotal = (float) $order->subtotal;
            $branchId = $table->zone?->branch_id;

            foreach ($request->items as $itemData) {
                $item = Item::findOrFail($itemData['item_id']);
                $qty = (int) $itemData['quantity'];
                $price = (float) ($branchId ? ($item->priceForBranch($branchId) ?? 0) : 0);
                $total = $price * $qty;

                OrderItem::create([
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'item_name' => $item->name,
                    'quantity' => $qty,
                    'price' => $price,
                    'total' => $total,
                    'notes' => $itemData['notes'] ?? null,
                ]);

                $subtotal += $total;
            }

            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal - ($order->discount_amount ?? 0),
            ]);

            LogFacade::info('Order created successfully', ['order_id' => $order->id]);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة الطلب بنجاح',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]
            ], 201);
        } catch (\Exception $e) {
            LogFacade::error('Order creation error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فشل إنشاء الطلب: ' . $e->getMessage(),
                'debug' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * استدعاء النادل
     * POST /api/customer/call-waiter
     */
    public function callWaiter(Request $request): JsonResponse
    {
        $request->validate([
            'qr_code' => 'required|string|exists:dining_tables,qr_code',
            'note' => 'nullable|string|max:255',
        ]);

        $table = DiningTable::where('qr_code', $request->qr_code)->firstOrFail();

        // هنا يمكن إرسال إشعار للنادل (نظام الإشعارات)
        // سيتم تطويره لاحقاً

        return response()->json([
            'success' => true,
            'message' => 'تم استدعاء النادل للطاولة ' . $table->table_number,
        ]);
    }

    /**
     * طلب الفاتورة
     * POST /api/customer/request-bill
     */
    public function requestBill(Request $request): JsonResponse
    {
        $request->validate([
            'qr_code' => 'required|string|exists:dining_tables,qr_code',
        ]);

        $table = DiningTable::where('qr_code', $request->qr_code)->firstOrFail();

        if ($table->current_order_id) {
            $table->setPaymentPending();
        }

        return response()->json([
            'success' => true,
            'message' => 'تم طلب الفاتورة للطاولة ' . $table->table_number,
        ]);
    }
}
