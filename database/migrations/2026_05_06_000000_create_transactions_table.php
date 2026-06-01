```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            // رقم القيد — يُولَّد تلقائياً (JV-YYYYMMDD-XXXX)
            $table->string('transaction_number')->unique()->nullable();

            $table->date('date');

            // رقم مرجعي خارجي (رقم الفاتورة، رقم الطلب، إلخ)
            $table->string('reference')->nullable();

            // نوع القيد
            $table->enum('type', [
                'sale',       // مبيعات
                'purchase',   // مشتريات
                'salary',     // رواتب
                'expense',    // مصروف
                'receipt',    // قبض
                'payment',    // دفع
                'journal',    // قيد يومية عام
                'opening',    // رصيد افتتاحي
                'adjustment', // تسوية
            ])->default('journal');

            // حالة القيد
            $table->enum('status', [
                'draft',      // مسودة — يمكن تعديله
                'posted',     // مرحَّل — لا يمكن تعديله
                'cancelled',  // ملغي
            ])->default('draft');

            $table->text('description')->nullable();

            // الفرع
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            // المستخدم المنشئ
            $table->unsignedBigInteger('user_id')->nullable();

            // Polymorphic Relation
            $table->nullableMorphs('source');

            // ── قيد العكس (Reversal) ───────────────────────────────
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->constrained('transactions')
                ->nullOnDelete();

            $table->boolean('is_reversal')->default(false);

            // ── الفترة المحاسبية ──────────────────────────────────
            $table->foreignId('period_id')
                ->nullable()
                ->constrained('accounting_periods')
                ->nullOnDelete();

            // ── العملة وسعر الصرف ────────────────────────────────
            $table->string('currency', 3)->default('ILS');

            $table->decimal('exchange_rate', 12, 6)
                ->default(1.000000);

            // ── الموافقة (Approval Workflow) ─────────────────────
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('approved_at')->nullable();

            // تاريخ الترحيل
            $table->timestamp('posted_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
