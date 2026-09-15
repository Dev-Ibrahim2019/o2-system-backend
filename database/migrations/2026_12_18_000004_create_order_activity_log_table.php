<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// سجل نشاط مركزي لكل طلب — من غيّر الحالة/الدفع/تعيين السائق/الإغلاق/إعادة الفتح، ومتى، ولماذا.
// جدول مستقل (وليس أعمدة إضافية على orders) عشان يدعم عدة أحداث لكل طلب بمرور الوقت (Timeline).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_type', 50); // status_change, payment, driver_assigned, driver_unassigned, closed, reopened...
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_activity_log');
    }
};
