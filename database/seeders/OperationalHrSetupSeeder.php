<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Branch;
use App\Models\JobTitle;
use Illuminate\Database\Seeder;

class OperationalHrSetupSeeder extends Seeder
{
    public function run(): void
    {
        $department = Department::updateOrCreate(
            ['name' => 'كول سنتر وعمليات الطلبات'],
            ['nameAr' => 'كول سنتر وعمليات الطلبات', 'type' => 'department', 'status' => 'ACTIVE', 'is_active' => true]
        );
        $department->branches()->syncWithoutDetaching(
            Branch::query()->pluck('id')->mapWithKeys(fn ($id) => [$id => ['is_active' => true]])->all()
        );

        foreach ([
            ['name' => 'موظف كول سنتر', 'name_ar' => 'موظف كول سنتر', 'name_en' => 'Call Center Agent', 'default_operational_role' => 'call_center_agent', 'requires_vehicle' => false],
            ['name' => 'مجمع طلبات', 'name_ar' => 'مجمع طلبات', 'name_en' => 'Order Assembler', 'default_operational_role' => 'assembler', 'requires_vehicle' => false],
            ['name' => 'دليفري', 'name_ar' => 'دليفري', 'name_en' => 'Delivery Driver', 'default_operational_role' => 'delivery_driver', 'requires_vehicle' => true],
        ] as $jobTitle) {
            JobTitle::updateOrCreate(
                ['name' => $jobTitle['name'], 'department_id' => $department->id],
                [...$jobTitle, 'department_id' => $department->id, 'is_active' => true]
            );
        }
    }
}
