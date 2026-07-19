<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            // ربط الطابعة بالفرع (لأن نظامك معزول الفروع Branch-isolated)
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');

            $table->string('name'); // اسم توضيحي مثل: طابعة كاشير 1، طابعة المطبخ الرئيسي، طابعة البار
            $table->string('ip_address'); // الـ IP الخاص بطابعة SNBC مثل: 192.168.1.192
            $table->string('port')->default('9100'); // البورت الافتراضي للطابعات الحرارية الشبكية هو 9100

            // نوع الطابعة لتسهيل الفلترة والتقسيم لاحقاً
            $table->enum('type', ['CASHIER', 'KITCHEN', 'BAR', 'OTHER'])->default('KITCHEN');

            $table->boolean('is_active')->default(true); // تفعيل أو إيقاف الطابعة مؤقتاً
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
    }
};
