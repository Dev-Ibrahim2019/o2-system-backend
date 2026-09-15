<?php
// app/Http/Resources/EmployeeResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'employeeId'    => $this->employeeId,
            'phone'         => $this->phone,
            'email'         => $this->email,
            'address'       => $this->address,
            'nationalId'    => $this->nationalId,
            'dob'           => $this->dob?->format('Y-m-d'),
            'image'         => $this->image,

            'branch_id'     => $this->branch_id,
            'branchId'      => $this->branch_id,
            'department_id' => $this->department_id,
            'departmentId'  => $this->department_id,
            'jobTitleId'    => $this->jobTitleId,
            'typeId'        => $this->typeId,
            'hireDate'      => $this->hireDate?->format('Y-m-d'),
            'salary'        => $this->salary,

            'role'          => $this->role,
            'operational_role' => $this->operational_role,
            'vehicle_type'  => $this->vehicle_type,
            'employee_code' => $this->employee_code,
            // متاح الآن فعليًا لسائقي التوصيل فقط (شفت مفتوح بـ driver_shifts) — يُستخدم لفلترة
            // قائمة "تعيين موظف توصيل" بصفحة الطلب. null لغير السائقين (لا معنى للحقل لهم).
            'available_now' => $this->when(
                $this->operational_role === 'delivery_driver',
                fn () => $this->isAvailableNow()
            ),
            'on_shift_now' => $this->when(
                $this->operational_role === 'delivery_driver',
                fn () => $this->onShiftNow()
            ),
            // نموذج التوفر ثنائي البعد: Status (نشط/غير نشط — عمود status العام) مستقل تمامًا عن
            // Availability (متاح/مع التوصيل/غير متصل) — محسوبة دائمًا من delivery_assignments،
            // "مع التوصيل" لا تُضبط يدويًا أبدًا. "مع التوصيل" = وصل الحد الأقصى (يدعم max > 1 مستقبلاً).
            'availability' => $this->when(
                $this->operational_role === 'delivery_driver',
                fn () => $this->activeDeliveryAssignmentsCount() >= $this->maxActiveDeliveries()
                    ? 'on_delivery'
                    : ($this->onShiftNow() ? 'available' : 'offline')
            ),
            // مصدر الحقيقة الجديد delivery_assignments (القسم 12) بدل عدّ orders.status مباشرة.
            'current_orders_count' => $this->when(
                $this->operational_role === 'delivery_driver',
                fn () => $this->activeDeliveryAssignmentsCount()
            ),
            'max_active_deliveries' => $this->when(
                $this->operational_role === 'delivery_driver',
                fn () => $this->maxActiveDeliveries()
            ),
            'is_eligible_for_assignment' => $this->when(
                $this->operational_role === 'delivery_driver',
                fn () => $this->isEligibleForNewAssignment()
            ),
            'completed_deliveries' => $this->when(
                $this->operational_role === 'delivery_driver',
                fn () => $this->driverOrders()->whereIn('status', ['DELIVERED', 'closed'])->count()
            ),
            'last_delivery_at' => $this->when(
                $this->operational_role === 'delivery_driver',
                fn () => $this->driverOrders()->whereNotNull('delivered_at')->max('delivered_at')
            ),
            'status'        => $this->status,
            'username'      => $this->username,
            'permissions'   => $this->permissions ?? [],
            'notes'         => $this->notes,
            'rating'        => $this->rating,
            'performance'   => $this->performance,

            'branch'        => $this->whenLoaded('branch', fn() => [
                'id'   => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'department'    => $this->whenLoaded('department', fn() => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
        ];
    }
}
