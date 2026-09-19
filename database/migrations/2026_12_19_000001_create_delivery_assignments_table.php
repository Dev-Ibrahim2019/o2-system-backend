<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// سجل تاريخي كامل لتعيينات السائقين — orders.driver_id يبقى للوصول السريع للتعيين الحالي فقط،
// لكن إعادة التعيين (Change Driver) تفقد كل تاريخها لو اعتمدنا عليه وحده (راجع القسم 12 بالبرومبت).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            // active: تعيين حالي ساري. completed: انتهى بتسليم ناجح. released: أُلغي تعيينه يدويًا
            // (Unassign/إلغاء طلب). reassigned: استُبدل بسائق آخر (Change Driver).
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'status']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignments');
    }
};
