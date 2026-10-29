<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * مستخدم تجريبي بدور storekeeper (أمين مخزن) للوحة /inventory.
 *
 *   php artisan db:seed --class=StorekeeperSeeder
 *
 * كلمة المرور من STOREKEEPER_SEED_PASSWORD في .env (لا تُكتب في الكود). يتطلب أن يكون
 * migration ‎2027_03_07_000001 مُطبّقاً (ينشئ الدور). يُربط بكل المخازن النشطة بمستوى "تعديل"
 * حتى تظهر له بيانات؛ عدّل الربط من صفحة المخازن.
 */
class StorekeeperSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('STOREKEEPER_SEED_PASSWORD');
        if (! is_string($password) || $password === '') {
            $this->command->error('STOREKEEPER_SEED_PASSWORD غير معرّف في .env — لم يُنشأ المستخدم.');

            return;
        }
        if (! Role::where('name', 'storekeeper')->where('guard_name', 'web')->exists()) {
            $this->command->error('دور storekeeper غير موجود — شغّل php artisan migrate أولاً.');

            return;
        }

        $username = env('STOREKEEPER_SEED_USERNAME', 'store');
        $user = User::updateOrCreate(
            ['username' => $username],
            ['name' => 'أمين المخزن', 'email' => "{$username}@resto.com", 'password' => Hash::make($password), 'branch_id' => null],
        );
        $user->syncRoles(['storekeeper']);

        $now = now();
        foreach (Warehouse::where('is_active', true)->pluck('id') as $warehouseId) {
            DB::table('warehouse_user')->updateOrInsert(
                ['warehouse_id' => $warehouseId, 'user_id' => $user->id],
                ['access_level' => 'edit', 'created_at' => $now],
            );
        }

        $this->command->info("✅ {$user->name} | user: {$user->username} | دور: storekeeper");
    }
}
