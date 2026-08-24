<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // العميل المرتبط بعملية الدفع — منسوخ من الفاتورة/الطلب وقت التحصيل
            // حتى يمكن بناء كشف حساب العميل مباشرة من جدول الدفعات دون الحاجة لربط الفاتورة كل مرة
            $table->foreignId('customer_id')->nullable()->after('invoice_id')
                ->constrained('customers')->nullOnDelete();

            // صندوق المبيعات الذي تم تحصيل الدفعة عليه — pos_registers أو call_center_registers
            // (نوعين منفصلين من الصناديق، لذلك نخزن النوع + المعرف بدل foreign key واحد)
            $table->string('register_type', 30)->nullable()->after('branch_id');
            $table->unsignedBigInteger('register_id')->nullable()->after('register_type');

            $table->index(['register_type', 'register_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropIndex(['register_type', 'register_id']);
            $table->dropColumn(['register_type', 'register_id']);
        });
    }
};
