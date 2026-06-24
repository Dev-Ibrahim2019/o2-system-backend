<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        // إعدادات افتراضية
        DB::table('discount_settings')->insert([
            [
                'key' => 'sales_discounts_account_code',
                'value' => '4120',
                'description' => 'كود حساب خصومات المبيعات في شجرة الحسابات',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'max_discount_percent',
                'value' => '100',
                'description' => 'أقصى نسبة خصم مسموحة',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'allow_compound_discounts',
                'value' => 'true',
                'description' => 'السماح بتطبيق أكثر من خصم على نفس الصنف',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'default_priority_mode',
                'value' => 'highest_first',
                'description' => 'طريقة الأولوية الافتراضية: highest_first, lowest_first, cumulative',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_settings');
    }
};
