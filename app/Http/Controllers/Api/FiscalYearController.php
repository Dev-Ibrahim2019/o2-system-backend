<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\FiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FiscalYearController extends ApiController
{
    /**
     * عرض جميع السنوات المالية مع إحصائيات
     */
    public function index(): JsonResponse
    {
        $fiscalYears = FiscalYear::withCount('shifts')
            ->withSum('shifts', 'total_sales')
            ->orderByDesc('start_date')
            ->get();

        return $this->success('تم جلب السنوات المالية', $fiscalYears);
    }

    /**
     * إنشاء سنة مالية جديدة
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:fiscal_years,name',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        // التحقق من عدم تداخل التواريخ مع سنة مالية أخرى
        $overlaps = FiscalYear::where('status', 'active')
            ->where(function ($q) use ($validated) {
                $q->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                    ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']])
                    ->orWhere(function ($q2) use ($validated) {
                        $q2->where('start_date', '<=', $validated['start_date'])
                            ->where('end_date', '>=', $validated['end_date']);
                    });
            })
            ->exists();

        if ($overlaps) {
            return $this->error('التواريخ تتداخل مع سنة مالية نشطة أخرى.', 422);
        }

        $fiscalYear = FiscalYear::create([
            'name' => $validated['name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => 'active',
            'created_by' => auth()->id(),
        ]);

        return $this->success('تم إنشاء السنة المالية بنجاح', $fiscalYear, 201);
    }

    /**
     * إغلاق سنة مالية
     */
    public function close(FiscalYear $fiscalYear): JsonResponse
    {
        if ($fiscalYear->status === 'closed') {
            return $this->error('السنة المالية مغلقة بالفعل.', 422);
        }

        $fiscalYear->close();

        return $this->success('تم إغلاق السنة المالية بنجاح', $fiscalYear->fresh());
    }

    /**
     * جلب السنة المالية النشطة
     */
    public function active(): JsonResponse
    {
        $active = FiscalYear::where('status', 'active')->first();

        return $this->success('السنة المالية النشطة', $active);
    }
}
