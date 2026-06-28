<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table) {
            $table->id();
            // ربط نقطة البيع بالفرع
            $table->foreignId('branch_id')->constrained('branches')->onDelete('cascade');

            $table->string('code')->unique(); // مثل: POS-001
            $table->string('name');           // اسم تعريفي (كاشير الصالة الرئيسي)

            // حقول الأمان الحصريّة (قفل الحديد والنار)
            $table->string('device_uuid')->nullable()->unique(); // معرف متصفح الفرع
            $table->string('activation_token')->nullable();      // الكود المؤقت (X89-TR4)
            $table->timestamp('token_expires_at')->nullable();  // وقت انتهاء الكود

            $table->enum('status', ['ACTIVE', 'INACTIVE', 'PENDING_ACTIVATION', 'REVOKED'])->default('PENDING_ACTIVATION');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_registers');
    }
};
