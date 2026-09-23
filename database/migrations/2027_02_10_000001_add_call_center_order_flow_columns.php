<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * أعمدة تدفق طلب الكول سنتر الكامل (دفع بنكي → تنفيذ فوري/مجدول → إغلاق → إزالة/تعديل):
 *
 * - payment_confirmations: تاريخ التحويل، اسم البنك، صورة الإشعار (كلها اختيارية) + محاولة لجعل
 *   الرقم المرجعي المُطبَّع فريدًا عالميًا (مش بس لكل طريقة دفع).
 * - production_tickets: نوع التذكرة (order/cancellation/amendment) وحالة الطباعة لكل قسم، عشان
 *   طابعة قسم فاصلة تتسجّل "فشل" مع إعادة طباعة بدل ما تضيع التذكرة.
 * - order_items: سبب الإلغاء لما يُزال صنف بعد التنفيذ (بيتلغى ولا بينحذف عشان تذكرة الإلغاء).
 * - orders: قفل التعديل المتزامن (editing_by / editing_until) بمهلة تنتهي لو الموظف سكّر الصفحة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_confirmations', function (Blueprint $table) {
            $table->date('transferred_at')->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('receipt_path')->nullable();
        });

        // الرقم المرجعي لازم يكون فريد عالميًا (مو لكل طريقة دفع). لو فيه بيانات قديمة مكرّرة بين
        // الطرق ما منغيّر أدلّتها المالية؛ منترك القيد القديم ويضل الفحص بالخدمة.
        $duplicates = DB::table('payment_confirmations')
            ->select('normalized_reference_number')
            ->groupBy('normalized_reference_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            Log::warning('payment_confirmations has duplicate normalized references across methods; global unique index skipped.');
        } else {
            Schema::table('payment_confirmations', function (Blueprint $table) {
                $table->unique('normalized_reference_number', 'payment_confirmations_normalized_reference_unique');
            });
        }

        Schema::table('production_tickets', function (Blueprint $table) {
            $table->string('type', 20)->default('order');
            $table->string('print_status', 20)->nullable();
            $table->string('print_error')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->unsignedTinyInteger('print_attempts')->default(0);
            // تذاكر الإلغاء/التعديل ما بتحمل ticket_items (قيد فريد على order_item_id): أسطر الفرق هون
            // [{name, quantity, action: cancel|add, notes}] وبتنطبع بنفس مسار تذاكر الأقسام.
            $table->json('lines')->nullable();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('cancel_reason')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('editing_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('editing_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editing_by');
            $table->dropColumn('editing_until');
        });
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('cancel_reason'));
        Schema::table('production_tickets', fn (Blueprint $table) => $table->dropColumn(['type', 'print_status', 'print_error', 'printed_at', 'print_attempts', 'lines']));
        Schema::table('payment_confirmations', function (Blueprint $table) {
            if (Schema::hasIndex('payment_confirmations', 'payment_confirmations_normalized_reference_unique')) {
                $table->dropUnique('payment_confirmations_normalized_reference_unique');
            }
            $table->dropColumn(['transferred_at', 'bank_name', 'receipt_path']);
        });
    }
};
