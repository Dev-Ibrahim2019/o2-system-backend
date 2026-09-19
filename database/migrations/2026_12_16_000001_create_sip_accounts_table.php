<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sip_accounts', function (Blueprint $table) {
            $table->id();

            // اسم عرض للحساب (مثال: "208 - كاشير الاستقبال")
            $table->string('account_name');

            // بيانات اعتماد SIP — تُدخَل يدوياً من واجهة الإعدادات، لا قيم افتراضية حقيقية هنا
            $table->string('username');
            $table->text('password'); // مُشفَّرة عبر cast: encrypted
            $table->string('sip_server');
            $table->string('domain')->nullable();
            $table->enum('transport', ['udp', 'tcp', 'tls'])->default('udp');
            $table->unsignedInteger('register_refresh')->default(300);
            $table->unsignedInteger('keep_alive')->default(15);

            $table->boolean('is_active')->default(true);

            // الموظف الذي يُستخدم هذا الحساب لتسجيل دخوله بالسماعة (اختياري)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sip_accounts');
    }
};
