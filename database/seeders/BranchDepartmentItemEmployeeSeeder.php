<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Item;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class BranchDepartmentItemEmployeeSeeder extends Seeder
{
    /**
     * Seed branches, departments (with pivot), items (with branch_item prices), and employees.
     */
    public function run(): void
    {
        $mainBranch = Branch::firstOrCreate(
            ['code' => 'MAIN'],
            Branch::factory()->main()->raw([
                'name' => 'Main Street',
                'address' => '1 City Center',
                'phone' => '0210000001',
                'openingTime' => '07:00:00',
                'closingTime' => '23:30:00',
            ])
        );

        $secondBranch = Branch::firstOrCreate(
            ['code' => 'NORTH'],
            Branch::factory()->raw([
                'name' => 'North Mall',
                'address' => '200 Mall Road',
                'phone' => '0210000002',
                'openingTime' => '09:00:00',
                'closingTime' => '22:00:00',
            ])
        );

        $kitchen = Department::firstOrCreate(
            ['code' => '1100'],
            Department::factory()->raw([
                'name' => 'Kitchen',
                'nameAr' => 'مطبخ',
                'type' => 'department',
                'shortName' => 'KIT',
                'hasKds' => true,
            ])
        );

        $bar = Department::firstOrCreate(
            ['code' => '1200'],
            Department::factory()->raw([
                'name' => 'Bar',
                'nameAr' => 'بار',
                'type' => 'department',
                'shortName' => 'BAR',
            ])
        );

        $service = Department::firstOrCreate(
            ['code' => '1300'],
            Department::factory()->raw([
                'name' => 'Service',
                'nameAr' => 'خدمة',
                'type' => 'department',
                'shortName' => 'SRV',
            ])
        );

        foreach ([$mainBranch, $secondBranch] as $branch) {
            foreach ([$kitchen, $bar, $service] as $department) {
                $branch->departments()->syncWithoutDetaching([
                    $department->id => ['is_active' => true],
                ]);
            }
        }

        $itemDefinitions = [
            ['department' => $kitchen, 'name' => 'Margherita Pizza', 'name_ar' => 'بيتزا مارجريتا', 'code' => 'KIT-PIZ-001', 'unit' => 'pcs'],
            ['department' => $kitchen, 'name' => 'Caesar Salad', 'name_ar' => 'سلطة قيصر', 'code' => 'KIT-SAL-001', 'unit' => 'pcs'],
            ['department' => $kitchen, 'name' => 'Grilled Chicken', 'name_ar' => 'دجاج مشوي', 'code' => 'KIT-CHK-001', 'unit' => 'pcs'],
            ['department' => $bar, 'name' => 'Fresh Orange Juice', 'name_ar' => 'عصير برتقال', 'code' => 'BAR-JUC-001', 'unit' => 'ltr'],
            ['department' => $bar, 'name' => 'Espresso', 'name_ar' => 'اسبريسو', 'code' => 'BAR-COF-001', 'unit' => 'pcs'],
            ['department' => $service, 'name' => 'Table Napkins Pack', 'name_ar' => 'مناديل', 'code' => 'SRV-NAP-001', 'unit' => 'box'],
        ];

        $items = collect($itemDefinitions)->map(function (array $row) {
            return Item::firstOrCreate(
                ['code' => $row['code']],
                Item::factory()->raw([
                    'department_id' => $row['department']->id,
                    'name' => $row['name'],
                    'name_ar' => $row['name_ar'],
                    'unit' => $row['unit'],
                ])
            );
        });

        foreach ($items as $index => $item) {
            $base = 12.5 + ($index * 2.25);

            $mainBranch->items()->syncWithoutDetaching([
                $item->id => ['price' => round($base, 3), 'is_active' => true],
            ]);

            $secondBranch->items()->syncWithoutDetaching([
                $item->id => ['price' => round($base + 1.5, 3), 'is_active' => true],
            ]);
        }

        Employee::updateOrCreate(
            ['username' => 'sara.manager'],
            Employee::factory()->raw([
                'employeeId' => 'EMP-000001',
                'name' => 'Sara Manager',
                'phone' => '0500000001',
                'email' => 'sara.manager@example.test',
                'branch_id' => $mainBranch->id,
                'department_id' => $service->id,
                'hireDate' => now()->subYears(3),
                'salary' => 9500.00,
                'role' => 'MANAGER',
                'username' => 'sara.manager',
                'password' => Hash::make('password'),
                'pin' => '1234',
            ])
        );

        Employee::updateOrCreate(
            ['username' => 'omar.chef'],
            Employee::factory()->raw([
                'employeeId' => 'EMP-000002',
                'name' => 'Omar Chef',
                'phone' => '0500000002',
                'email' => 'omar.chef@example.test',
                'branch_id' => $mainBranch->id,
                'department_id' => $kitchen->id,
                'hireDate' => now()->subYears(2),
                'salary' => 7200.00,
                'role' => 'EMPLOYEE',
                'username' => 'omar.chef',
                'password' => Hash::make('password'),
                'pin' => '5678',
            ])
        );

        Employee::updateOrCreate(
            ['username' => 'layla.bar'],
            Employee::factory()->raw([
                'employeeId' => 'EMP-000003',
                'name' => 'Layla Barista',
                'phone' => '0500000003',
                'email' => 'layla.bar@example.test',
                'branch_id' => $secondBranch->id,
                'department_id' => $bar->id,
                'hireDate' => now()->subYear(),
                'salary' => 5800.00,
                'role' => 'EMPLOYEE',
                'username' => 'layla.bar',
                'password' => Hash::make('password'),
                'pin' => '9012',
            ])
        );

        Employee::factory(4)->create([
            'branch_id' => $secondBranch->id,
            'department_id' => $service->id,
            'hireDate' => now()->subMonths(6),
        ]);
    }
}
