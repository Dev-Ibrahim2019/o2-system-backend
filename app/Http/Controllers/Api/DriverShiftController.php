<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\DriverShift;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

/**
 * تتبع شفتات/توفر سائقي التوصيل — يحدد أي موظف operational_role=delivery_driver متاح للتعيين
 * فعليًا الآن، يُستخدم لفلترة قائمة "تعيين موظف توصيل" بصفحة تفاصيل الطلب.
 */
class DriverShiftController extends ApiController
{
    public function start(Employee $employee): JsonResponse
    {
        if ($employee->operational_role !== 'delivery_driver') {
            return $this->error('الموظف المحدد ليس سائق توصيل.', 422);
        }

        if ($employee->isAvailableNow()) {
            return $this->error('يوجد شفت مفتوح لهذا السائق أصلاً.', 422);
        }

        $shift = DriverShift::create([
            'employee_id' => $employee->id,
            'shift_start' => now(),
            'is_available' => true,
        ]);

        return $this->success('تم بدء الشفت', $shift);
    }

    public function end(Employee $employee): JsonResponse
    {
        $shift = $employee->driverShifts()->whereNull('shift_end')->latest('shift_start')->first();

        if (! $shift) {
            return $this->error('لا يوجد شفت مفتوح لهذا السائق.', 422);
        }

        $shift->update(['shift_end' => now(), 'is_available' => false]);

        return $this->success('تم إنهاء الشفت', $shift);
    }
}
