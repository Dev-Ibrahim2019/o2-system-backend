<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Item;
use App\Models\Printer;
use App\Models\PrintRoute;
use Illuminate\Database\Seeder;

class PrinterSeeder extends Seeder
{
    public function run(): void
    {
        $branchId = 1;

        $kitchenDept = Department::where('code', '1100')->first();
        $barDept = Department::where('code', '1200')->first();

        $grilledChicken = Item::where('code', 'KIT-CHK-001')->first();

        $cashierPrinter = Printer::create([
            'name'       => 'طابعة كاشير الصالة الرئيسي',
            'ip_address' => '192.168.1.62',
            'port'       => '9100',
            'type'       => 'CASHIER',
            'branch_id'  => $branchId,
            'is_active'  => true,
        ]);

        $kitchenPrinter = Printer::create([
            'name'       => 'طابعة قسم المشاوي والفرن (SNBC)',
            'ip_address' => '192.168.1.62',
            'port'       => '9100',
            'type'       => 'KITCHEN',
            'branch_id'  => $branchId,
            'is_active'  => true,
        ]);

        $barPrinter = Printer::create([
            'name'       => 'طابعة بار العصائر والمشروبات',
            'ip_address' => '192.168.1.62',
            'port'       => '9100',
            'type'       => 'BAR',
            'branch_id'  => $branchId,
            'is_active'  => true,
        ]);

        if ($kitchenDept) {
            PrintRoute::create([
                'branch_id'   => $branchId,
                'printer_id'  => $kitchenPrinter->id,
                'user_id'     => null,
                'category_id' => $kitchenDept->id,
                'item_id'     => null,
                'action_type' => 'KOT',
                'is_active'   => true,
            ]);
        }

        if ($grilledChicken) {
            PrintRoute::create([
                'branch_id'   => $branchId,
                'printer_id'  => $cashierPrinter->id,
                'user_id'     => null,
                'category_id' => null,
                'item_id'     => $grilledChicken->id,
                'action_type' => 'KOT',
                'is_active'   => true,
            ]);
        }

        if ($barDept) {
            PrintRoute::create([
                'branch_id'   => $branchId,
                'printer_id'  => $barPrinter->id,
                'user_id'     => null,
                'category_id' => $barDept->id,
                'item_id'     => null,
                'action_type' => 'KOT',
                'is_active'   => true,
            ]);
        }

        $this->command->info('تم انشاء 3 طابعات و 3 قواعد توجيه بنجاح');
    }
}
