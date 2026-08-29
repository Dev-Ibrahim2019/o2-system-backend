<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        // ربط تبويب "أرقام الحسابات" — حسابات عامة على مستوى المنشأة، تُعرض
        // بالكاشير للاطلاع فقط، تُدار فعلياً من لوحة الإدارة
        DB::table('accounting_settings')->insert([
            ['key' => 'allowed_discount_account', 'value' => null, 'description' => 'حساب الخصم المسموح', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'vat_account', 'value' => null, 'description' => 'حساب الضريبة المضافة', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'service_charge_account', 'value' => null, 'description' => 'حساب بدل الخدمة', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'minimum_order_limit_account', 'value' => null, 'description' => 'حساب الحد الأدنى', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'sales_revenue_account', 'value' => null, 'description' => 'حساب إيراد المبيعات', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'extra_chairs_account', 'value' => null, 'description' => 'حساب زبون اضافي', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_settings');
    }
};
