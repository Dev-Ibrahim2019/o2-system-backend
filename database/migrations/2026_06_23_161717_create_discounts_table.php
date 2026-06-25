<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('name_ar', 255)->nullable();
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();

            // نوع الخصم: percentage, fixed_amount, price_override, buy_x_get_y
            $table->enum('discount_type', ['percentage', 'fixed_amount', 'price_override', 'buy_x_get_y']);

            // قيمة الخصم: نسبة/مبلغ ثابت/سعر جديد
            $table->decimal('value', 15, 3)->default(0);

            // الأولوية — الأصغر = الأسبق في التطبيق
            $table->integer('priority')->default(0);

            // صلاحية الخصم
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // تفعيل/تعطيل
            $table->boolean('is_active')->default(true);

            // منشئ الخصم
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // لأغراض التدقيق
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Buy X Get Y — للحجز المستقبلي
            $table->integer('buy_quantity')->nullable();
            $table->integer('get_quantity')->nullable();
            $table->decimal('get_discount_percent', 5, 2)->nullable();

            // شروط إضافية (مثل حد أقصى للخصم)
            $table->decimal('max_discount_amount', 15, 3)->nullable();
            $table->decimal('min_order_amount', 15, 3)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
