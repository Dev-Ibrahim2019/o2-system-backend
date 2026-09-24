<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "الخانات" (Slots) للطلبات النشطة بالكول سنتر: كل طلب ينتظر الدفع بياخد أصغر رقم خانة فاضي
 * بفرعه ويضل فيها لحد ما يتدفع أو يتلغى/يتسكّر.
 *
 * الجدول بيحتفظ بالسجل كامل (الصف المحرَّر بيضل مع released_at) عشان يخدم "سجل الخانة" والـcooldown
 * قبل إعادة استخدام الرقم. ما في MySQL partial unique index، فالتفرّد على الخانات "المشغولة حاليًا"
 * مضمون بعمودين nullable (active_slot / active_order_id) بيتعبّوا لما الصف نشط وبيصيروا NULL عند
 * التحرير — وMySQL بيسمح بأكتر من NULL بالـunique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // null = استخدم القيمة الافتراضية من config('call-center.slots.default_capacity')
            $table->unsignedSmallInteger('order_slot_capacity')->nullable();
        });

        Schema::create('order_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('slot_number');
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 30)->nullable();

            $table->unsignedSmallInteger('active_slot')->nullable();
            $table->unsignedBigInteger('active_order_id')->nullable();

            $table->unique(['branch_id', 'active_slot'], 'order_slots_branch_active_slot_unique');
            $table->unique('active_order_id', 'order_slots_active_order_unique');
            $table->index(['branch_id', 'slot_number', 'released_at'], 'order_slots_branch_slot_released_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_slots');
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('order_slot_capacity');
        });
    }
};
