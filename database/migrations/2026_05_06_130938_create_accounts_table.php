<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code')->unique();

            // نوع الحساب — يحدد طبيعة الرصيد
            $table->enum('type', [
                'asset',      // أصول       — طبيعتها مدينة (debit)
                'liability',  // التزامات   — طبيعتها دائنة (credit)
                'equity',     // حقوق ملكية — طبيعتها دائنة (credit)
                'revenue',    // إيرادات    — طبيعتها دائنة (credit)
                'expense',    // مصاريف     — طبيعتها مدينة (debit)
            ]);

            // ✅ إضافة: طبيعة الحساب (مدين / دائن) للحساب التلقائي للأرصدة
            $table->enum('normal_balance', ['debit', 'credit'])->default('debit');

            // شجرة الحسابات (دليل الحسابات)
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete();

            // ✅ إضافة: المستوى في الشجرة (1=رئيسي، 2=فرعي، 3=تفصيلي)
            $table->unsignedTinyInteger('level')->default(1);

            // ✅ إضافة: هل يقبل القيود المباشرة (الحسابات الأم لا تقبل)
            $table->boolean('allow_posting')->default(true);

            $table->boolean('is_active')->default(true);

            // حسابات النظام لا يمكن حذفها (النقدية، الأرباح، إلخ)
            $table->boolean('is_system')->default(false);

            // ✅ إضافة: ملاحظات
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
