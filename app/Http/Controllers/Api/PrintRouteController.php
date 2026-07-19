<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\PrintRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PrintRouteController extends Controller
{
    /**
     * جلب جميع قواعد التوجيه للفرع الحالي
     */
    public function index(Request $request)
    {
        $branchId = $this->resolveBranchId($request);

        $query = PrintRoute::with(['printer', 'user', 'category', 'item', 'posRegister', 'hospitalityDevice'])
            ->orderByDesc('created_at');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $routes = $query->get();

        return response()->json([
            'success' => true,
            'data'    => $routes,
        ]);
    }

    /**
     * إنشاء قاعدة توجيه جديدة
     * يتم تحديد النطاق تلقائياً: إذا أُرسل item_id = ITEM، إذا أُرسل category_id = CATEGORY
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'printer_id'            => 'required|integer|exists:printers,id',
            'user_id'               => 'nullable|integer|exists:users,id',
            'pos_register_id'       => 'nullable|integer|exists:pos_registers,id',
            'hospitality_device_id' => 'nullable|integer|exists:hospitality_devices,id',
            'category_id'           => 'nullable|integer|exists:departments,id',
            'item_id'               => 'nullable|integer|exists:items,id',
            'action_type'           => 'nullable|string|in:KOT,BILL',
        ]);

        $branchId = $this->resolveBranchId($request);

        if ($branchId === null) {
            return response()->json([
                'success' => false,
                'message' => 'branch_id مطلوب. يرجى تحديد الفرع أو التأكد من أن المستخدم مرتبط بفرع.',
            ], 400);
        }

        // تحديد النطاق تلقائياً
        $hasItem = !empty($validated['item_id']);
        $hasCategory = !empty($validated['category_id']);

        if (!$hasItem && !$hasCategory) {
            throw ValidationException::withMessages([
                'item_id' => 'يجب تحديد قسم (category_id) أو صنف (item_id) على الأقل',
            ]);
        }

        if ($hasItem && $hasCategory) {
            throw ValidationException::withMessages([
                'item_id' => 'لا يمكن تحديد قسم وصنف معاً في نفس القاعدة',
            ]);
        }

        // التأكد من أن الطابعة تابعة للفرع
        Printer::where('branch_id', $branchId)
            ->where('id', $validated['printer_id'])
            ->firstOrFail();

        // فحص التكرار
        $duplicate = PrintRoute::where('branch_id', $branchId)
            ->where('printer_id', $validated['printer_id'])
            ->where('category_id', $validated['category_id'] ?? null)
            ->where('item_id', $validated['item_id'] ?? null)
            ->where('user_id', $validated['user_id'] ?? null)
            ->where('pos_register_id', $validated['pos_register_id'] ?? null)
            ->where('hospitality_device_id', $validated['hospitality_device_id'] ?? null)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'printer_id' => 'قاعدة توجيه مماثلة موجودة مسبقاً لهذا الهدف والطابعة',
            ]);
        }

        $route = PrintRoute::create([
            'branch_id'            => $branchId,
            'printer_id'           => $validated['printer_id'],
            'user_id'              => $validated['user_id'] ?? null,
            'pos_register_id'      => $validated['pos_register_id'] ?? null,
            'hospitality_device_id'=> $validated['hospitality_device_id'] ?? null,
            'category_id'          => $validated['category_id'] ?? null,
            'item_id'              => $validated['item_id'] ?? null,
            'action_type'          => $validated['action_type'] ?? 'KOT',
            'is_active'            => true,
        ]);

        $route->load(['printer', 'user', 'category', 'item', 'posRegister', 'hospitalityDevice']);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء قاعدة التوجيه',
            'data'    => $route,
        ], 201);
    }

    /**
     * تحديث قاعدة توجيه
     */
    public function update(Request $request, $id)
    {
        $branchId = $this->resolveBranchId($request);

        $route = PrintRoute::where('branch_id', $branchId)->findOrFail($id);

        $validated = $request->validate([
            'printer_id'            => 'sometimes|integer|exists:printers,id',
            'user_id'               => 'nullable|integer|exists:users,id',
            'pos_register_id'       => 'nullable|integer|exists:pos_registers,id',
            'hospitality_device_id' => 'nullable|integer|exists:hospitality_devices,id',
            'category_id'           => 'nullable|integer|exists:departments,id',
            'item_id'               => 'nullable|integer|exists:items,id',
            'action_type'           => 'nullable|string|in:KOT,BILL',
            'is_active'             => 'sometimes|boolean',
        ]);

        $route->update($validated);
        $route->load(['printer', 'user', 'category', 'item', 'posRegister', 'hospitalityDevice']);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث القاعدة',
            'data'    => $route->fresh(['printer', 'user', 'category', 'item', 'posRegister', 'hospitalityDevice']),
        ]);
    }

    /**
     * حذف قاعدة توجيه
     */
    public function destroy(Request $request, $id)
    {
        $branchId = $this->resolveBranchId($request);

        $route = PrintRoute::where('branch_id', $branchId)->findOrFail($id);
        $route->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف القاعدة',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function resolveBranchId(Request $request): ?int
    {
        if ($request->has('branch_id')) {
            return (int) $request->branch_id;
        }

        $user = Auth::user();
        if ($user && $user->branch_id) {
            return (int) $user->branch_id;
        }

        $branchHeader = $request->header('X-Branch-Id');
        if ($branchHeader) {
            return (int) $branchHeader;
        }

        // Fallback: أول فرع في قاعدة البيانات (للمستخدمين العامين)
        $firstBranch = \App\Models\Branch::first();
        if ($firstBranch) {
            return (int) $firstBranch->id;
        }

        return null;
    }
}
