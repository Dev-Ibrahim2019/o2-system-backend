<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        $users = [
            // ── 1. مدير النظام ──
            [
                'name'     => 'Super Admin',
                'username' => 'admin',
                'email'    => 'admin@resto.com',
                'role'     => 'super-admin',
            ],

            // ── 2. مدير الفرع ──
            [
                'name'     => 'Branch Manager',
                'username' => 'manager',
                'email'    => 'manager@resto.com',
                'role'     => 'branch-manager',
            ],

            // ── 3. المحاسب ──
            [
                'name'     => 'Accountant',
                'username' => 'accountant',
                'email'    => 'accountant@resto.com',
                'role'     => 'accountant',
            ],

            // ── 4. الكاشير ──
            [
                'name'     => 'Cashier',
                'username' => 'cashier',
                'email'    => 'cashier@resto.com',
                'role'     => 'cashier',
            ],

            // ── 5. موظف الضيافة ──
            [
                'name'     => 'Hospitality Staff',
                'username' => 'hospitality',
                'email'    => 'hospitality@resto.com',
                'role'     => 'hospitality',
            ],

            // ── 6. موظف القسم ──
            [
                'name'     => 'Department Staff',
                'username' => 'deptstaff',
                'email'    => 'deptstaff@resto.com',
                'role'     => 'dept-staff',
            ],
        ];

        foreach ($users as $userData) {
            $role = $userData['role'];
            unset($userData['role']);

            $user = User::firstOrCreate(
                ['username' => $userData['username']],
                [
                    'name'     => $userData['name'],
                    'email'    => $userData['email'],
                    'password' => $password,
                ]
            );

            // تعيين الدور (بدون تكرار)
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            $this->command->info("✅ {$user->name} | username: {$user->username} | الدور: {$role} | كلمة المرور: password");
        }

        $this->command->newLine();
        $this->command->info('🎉 تم إنشاء ' . count($users) . ' مستخدمين تجريبيين بنجاح!');
    }
}
