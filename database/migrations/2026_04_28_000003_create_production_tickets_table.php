<?php
// database/migrations/2026_04_28_000003_create_production_tickets_table.php
//
// تذاكر الإنتاج: كل طلب يُولِّد تذكرة لكل قسم إنتاجي معني
// مثلاً: طلب فيه كابتشينو + برغر → تذكرة للبار + تذكرة للمطبخ

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_tickets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();

            // رقم التذكرة داخل القسم (لعرضه على شاشة المطبخ/البار)
            $table->string('ticket_number');

            $table->enum('status', [
                'pending',      // وصل للقسم، لم يبدأ بعد
                'preparing',    // بدأ التحضير
                'ready',        // جاهز، ينتظر الكاشير
                'cancelled',    // ملغي
            ])->default('pending');

            // الوقت الفعلي للبدء والانتهاء (للإحصاء)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // ملاحظة للقسم
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_tickets');
    }
};
