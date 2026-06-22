<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        // ── تأكد من وجود فروع أولاً (للربط مع المستخدمين) ──
        $branch1 = Branch::firstOrCreate(['code' => 'GAZA-01'], [
            'name'         => 'فرع غزة الرئيسي',
            'address'      => 'شارع الشجاعية',
            'phone'        => '0599001122',
            'is_active'    => true,
            'isMainBranch' => true,
            'openingTime'  => '08:00',
            'closingTime'  => '23:00',
        ]);

        $branch2 = Branch::firstOrCreate(['code' => 'NSRS-01'], [
            'name'         => 'فرع النصيرات',
            'address'      => 'شارع الوسطى',
            'phone'        => '0599112233',
            'is_active'    => true,
            'isMainBranch' => false,
            'openingTime'  => '09:00',
            'closingTime'  => '22:00',
        ]);

        $branch3 = Branch::firstOrCreate(['code' => 'KHAN-01'], [
            'name'         => 'فرع خانيونس',
            'address'      => 'شارع السلام',
            'phone'        => '0599223344',
            'is_active'    => true,
            'isMainBranch' => false,
            'openingTime'  => '08:00',
            'closingTime'  => '23:00',
        ]);

        $this->command->info("✅ تم التأكد من وجود 3 فروع");

        // ── المستخدمون مع ربطهم بالفروع ──
        $users = [
            // super-admin → لا ينتمي لفرع (يرى كل شيء)
            [
                'name'      => 'Super Admin',
                'username'  => 'admin',
                'email'     => 'admin@resto.com',
                'role'      => 'super-admin',
                'branch_id' => null,
            ],
            // branch-manager → فرع غزة
            [
                'name'      => 'مدير فرع غزة',
                'username'  => 'gaza_manager',
                'email'     => 'gaza_manager@resto.com',
                'role'      => 'branch-manager',
                'branch_id' => $branch1->id,
            ],
            // branch-manager → فرع النصيرات
            [
                'name'      => 'مدير فرع النصيرات',
                'username'  => 'nsrs_manager',
                'email'     => 'nsrs_manager@resto.com',
                'role'      => 'branch-manager',
                'branch_id' => $branch2->id,
            ],
            // accountant → فرع غزة (محاسب الفرع)
            [
                'name'      => 'محاسب فرع غزة',
                'username'  => 'gaza_acc',
                'email'     => 'gaza_acc@resto.com',
                'role'      => 'accountant',
                'branch_id' => $branch1->id,
            ],
            // cashier → فرع غزة
            [
                'name'      => 'كاشير فرع غزة',
                'username'  => 'gaza_cashier',
                'email'     => 'gaza_cashier@resto.com',
                'role'      => 'cashier',
                'branch_id' => $branch1->id,
            ],
            // cashier → فرع النصيرات
            [
                'name'      => 'كاشير فرع النصيرات',
                'username'  => 'nsrs_cashier',
                'email'     => 'nsrs_cashier@resto.com',
                'role'      => 'cashier',
                'branch_id' => $branch2->id,
            ],
            // hospitality → فرع خانيونس
            [
                'name'      => 'ضيافة فرع خانيونس',
                'username'  => 'khan_hosp',
                'email'     => 'khan_hosp@resto.com',
                'role'      => 'hospitality',
                'branch_id' => $branch3->id,
            ],
            // dept-staff → فرع غزة
            [
                'name'      => 'موظف قسم غزة',
                'username'  => 'gaza_dept',
                'email'     => 'gaza_dept@resto.com',
                'role'      => 'dept-staff',
                'branch_id' => $branch1->id,
            ],
        ];

        foreach ($users as $userData) {
            $role = $userData['role'];
            $branchId = $userData['branch_id'];
            unset($userData['role']);
            unset($userData['branch_id']);

            $user = User::updateOrCreate(
                ['username' => $userData['username']],
                [
                    'name'      => $userData['name'],
                    'email'     => $userData['email'],
                    'password'  => $password,
                    'branch_id' => $branchId,
                ]
            );

            if (! $user->hasRole($role)) {
                $user->syncRoles([$role]);
            }

            $branchName = $branchId ? Branch::find($branchId)?->name ?? "فرع #{$branchId}" : 'كل الفروع';
            $this->command->info("✅ {$user->name} | user: {$user->username} | دور: {$role} | فرع: {$branchName}");
        }

        // ── ربط الأقسام بالفروع عبر branch_department ──
        $departments = \App\Models\Department::all();
        $branches = [$branch1, $branch2, $branch3];

        foreach ($departments as $dept) {
            foreach ($branches as $branch) {
                \DB::table('branch_department')->updateOrInsert(
                    ['department_id' => $dept->id, 'branch_id' => $branch->id],
                    ['is_active' => true]
                );
            }
        }

        $this->command->info('✅ تم ربط ' . $departments->count() . ' أقسام بـ ' . count($branches) . ' فروع');

        $this->command->newLine();
        $this->command->info(' تم إنشاء ' . count($users) . ' مستخدمين بنجاح!');
        $this->command->newLine();
        $this->command->info('📋 للاختبار:');
        $this->command->info('   سجّل دخول بـ: admin / password → يرى كل شيء');
        $this->command->info('   سجّل دخول بـ: gaza_manager / password → يرى فرع غزة فقط');
        $this->command->info('   سجّل دخول بـ: nsrs_manager / password → يرى فرع النصيرات فقط');
    }
}
