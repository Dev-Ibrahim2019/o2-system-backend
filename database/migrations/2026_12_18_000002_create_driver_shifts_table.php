<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// تتبّع شفتات/توفر سائقي التوصيل — يحدد أي موظف operational_role=delivery_driver متاح للتعيين
// الآن فعليًا (مش كل من عنده الدور، بغض النظر عن كونه بدوام). لا يوجد جدول موازٍ لبيانات السائق
// الثابتة (الاسم/الهاتف/نوع المركبة) — تلك موجودة أصلاً على employees نفسها.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('shift_start');
            $table->timestamp('shift_end')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index(['employee_id', 'is_available']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_shifts');
    }
};
