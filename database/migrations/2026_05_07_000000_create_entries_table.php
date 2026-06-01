<?php
// ✅ هاد الملف بعد cost_centers في الترتيب

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();

            // ربط القيد
            $table->foreignId('transaction_id')
                ->constrained('transactions')
                ->cascadeOnDelete();

            // الحساب المحاسبي
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->restrictOnDelete(); // ❌ لا تحذف حساباً فيه قيود

            // المبالغ (مدين / دائن) — أحدهما > 0 والآخر = 0
            $table->decimal('debit', 15, 3)->default(0);
            $table->decimal('credit', 15, 3)->default(0);

            $table->text('description')->nullable();

            // ✅ مركز التكلفة — constrained صحيحة الآن (cost_centers موجود قبلها)
            $table->foreignId('cost_center_id')
                ->nullable()
                ->constrained('cost_centers')
                ->nullOnDelete();

            // ✅ ترتيب السطر داخل القيد
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
