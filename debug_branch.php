<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

// 1. المستخدم gaza_cashier
$user = DB::table('users')->where('username', 'gaza_cashier')->first();
echo "=== المستخدم gaza_cashier ===\n";
echo "ID: " . ($user ? $user->id : 'NOT FOUND') . "\n";
echo "branch_id: " . ($user ? $user->branch_id : 'N/A') . "\n";
echo "name: " . ($user ? $user->name : 'N/A') . "\n\n";

// 2. نقطة البيع POS-004
$pos = DB::table('pos_registers')->where('code', 'POS-004')->first();
echo "=== نقطة البيع POS-004 ===\n";
echo "ID: " . ($pos ? $pos->id : 'NOT FOUND') . "\n";
echo "branch_id: " . ($pos ? $pos->branch_id : 'N/A') . "\n";
echo "device_uuid: " . ($pos ? ($pos->device_uuid ?: 'NULL') : 'N/A') . "\n";
echo "status: " . ($pos ? $pos->status : 'N/A') . "\n\n";

// 3. الأفراع
$branches = DB::table('branches')->select('id', 'name')->get();
echo "=== الأفراع ===\n";
foreach ($branches as $b) {
    echo "ID: {$b->id} - {$b->name}\n";
}

// 4. هل المستخدم عنده دور cashier؟
$userId = $user ? $user->id : 0;
$roles = DB::table('model_has_roles')
    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
    ->where('model_has_roles.model_id', $userId)
    ->pluck('roles.name')
    ->toArray();
echo "\n=== أدوار gaza_cashier ===\n";
echo implode(', ', $roles) . "\n";

// 5. فرع المستخدم的真实
$userObj = \App\Models\User::find($userId);
echo "\n===loquent branch_id===\n";
echo "branch_id: " . ($userObj ? $userObj->branch_id : 'N/A') . "\n";
