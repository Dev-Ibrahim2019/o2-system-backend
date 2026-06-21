<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. إنشاء الصلاحيات والأدوار أولاً
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);

        // 2. إنشاء المستخدمين التجريبيين وتعيين الأدوار
        $this->call([
            TestUsersSeeder::class,
        ]);

        // 3. إنشاء الأقسام والأصناف وربطها بالفروع
        $this->call([
            BranchDepartmentItemEmployeeSeeder::class,
        ]);

        // 4. باقي الـ Seeders
        $this->call([
            ChartOfAccountsSeeder::class,
            PaymentMethodSeeder::class,
        ]);
    }
}
