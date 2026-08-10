<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShiftController extends ApiController
{
    /**
     * عاليوميات (مع فلترة حسب الفرع والتاريخ والسنة المالية)
     */
    public function index(Request $request): JsonResponse
    {
        $shifts = Shift::with(['opener', 'closer', 'fiscalYear'])
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->date, fn($q) => $q->whereDate('date', $request->date))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->fiscal_year_id, fn($q) => $q->where('fiscal_year_id', $request->fiscal_year_id))
            ->orderByDesc('id')
            ->paginate(50);

        return $this->success('تم جلب اليوميات', $shifts);
    }

    /**
     * اليومية النشطة للفرع الحالي
     */
    public function current(): JsonResponse
    {
        $user = auth()->user();
        $branchId = $user->branch_id;

        $shift = Shift::with(['opener', 'fiscalYear'])
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->whereDate('date', now()->toDateString())
            ->first();

        if (!$shift) {
            return $this->success('لا توجد يومية نشطة', null);
        }

        // إحصائيات الطلبات في اليومية
        $stats = [
            'total_orders' => $shift->orders()->count(),
            'paid_orders' => $shift->orders()->where('status', 'paid')->count(),
            'open_orders' => $shift->orders()->whereIn('status', ['pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready'])->count(),
            'total_sales' => (float) $shift->orders()->where('status', 'paid')->sum('total'),
        ];

        return $this->success('اليومية النشطة', [
            'shift' => $shift,
            'stats' => $stats,
        ]);
    }

    /**
     * الترحيل السريع (Rollover)
     *
     * 1. إغلاق الـ shift الحالي
     * 2. حساب إجمالي المبيعات
     * 3. إنشاء shift جديد
     * 4. نقل الطلبات المفتوحة للـ shift الجديد
     */
    public function rollover(Request $request): JsonResponse
    {
        $user = auth()->user();
        $branchId = $user->branch_id;

        $validated = $request->validate([
            'closing_balance' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            // 1. البحث عن الـ shift المفتوح
            $currentShift = Shift::where('branch_id', $branchId)
                ->where('status', 'open')
                ->whereDate('date', now()->toDateString())
                ->first();

            if (!$currentShift) {
                DB::rollBack();
                return $this->error('لا توجد يومية مفتوحة للترحيل.', 422);
            }

            // 2. إغلاق الـ shift الحالي
            $totalSales = $currentShift->orders()
                ->where('status', 'paid')
                ->sum('total');

            $currentShift->update([
                'status' => 'closed',
                'closed_by' => $user->id,
                'closed_at' => now(),
                'closing_balance' => $validated['closing_balance'] ?? 0,
                'total_sales' => $totalSales,
            ]);

            // 3. نقل الطلبات المفتوحة للـ shift الجديد
            $openStatuses = ['pending', 'pending_confirmation', 'confirmed', 'in_progress', 'ready', 'pending_payment'];

            // 4. البحث عن السنة المالية النشطة لتاريخ اليوم
            $fiscalYear = FiscalYear::findForDate(now());

            // 5. إنشاء shift جديد
            $newShift = Shift::create([
                'branch_id' => $branchId,
                'fiscal_year_id' => $fiscalYear?->id,
                'opened_by' => $user->id,
                'date' => now()->toDateString(),
                'opened_at' => now(),
                'status' => 'open',
                'opening_balance' => $validated['closing_balance'] ?? 0,
            ]);

            // 6. نقل الطلبات المفتوحة
            Order::where('shift_id', $currentShift->id)
                ->whereIn('status', $openStatuses)
                ->update(['shift_id' => $newShift->id]);

            DB::commit();

            return $this->success('تم الترحيل بنجاح', [
                'closed_shift' => [
                    'id' => $currentShift->id,
                    'total_sales' => $totalSales,
                    'closed_at' => now()->toISOString(),
                ],
                'new_shift' => $newShift->load(['opener', 'fiscalYear']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل الترحيل: ' . $e->getMessage(), 500);
        }
    }
}
