<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 3: المستندات المخزنية (استلام مشتريات، تحويل، هالك) وربط الأصناف بالموردين.
 *
 * المستند: مسودة ← مرحّل ← معكوس. المسودة تُعدَّل وتُحذف؛ الترحيل يسجّل الحركات عبر StockLedger،
 * والمستند المرحّل لا يُعدَّل — يُصحَّح بعكسه كاملاً (حركات عكسية بتكلفتها الأصلية).
 * مستند الاستلام مخزني فقط ولا يُنشئ قيداً محاسبياً (القرار 13)؛ فاتورة الشراء المحاسبية
 * (purchase_bills) تبقى كما هي، ورقم فاتورة المورد يُحفظ هنا للمطابقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('name_en')->nullable()->after('name');
            // وحدة الشراء الافتراضية (كرتونة، شوال 25 كغ...) — يجب أن تكون الأساسية أو إحدى البديلة.
            $table->foreignId('purchase_unit_id')->nullable()->after('base_unit_id')->constrained('stock_units')->restrictOnDelete();
            $table->foreignId('default_warehouse_id')->nullable()->after('purchase_unit_id')->constrained('warehouses')->nullOnDelete();
            // آخر تكلفة شراء لكل وحدة أساسية — تُحدَّث عند ترحيل الاستلام. متوسط التكلفة لكل مخزن في stock_balances.
            $table->decimal('last_cost', 18, 6)->nullable()->after('safety_stock');
        });

        Schema::create('supplier_stock_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('stock_units')->restrictOnDelete();
            // سعر المورّد للوحدة المحددة أعلاه (أو الوحدة الأساسية إن لم تُحدد).
            $table->decimal('price', 18, 6)->nullable();
            $table->string('supplier_item_code', 64)->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();

            $table->unique(['supplier_id', 'stock_item_id']);
            $table->index(['stock_item_id', 'is_preferred']);
        });

        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->enum('type', ['receipt', 'transfer', 'waste']);
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('draft');
            // المخزن المصدر/الوحيد، والمخزن الوجهة للتحويل فقط.
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->string('supplier_invoice_no', 64)->nullable();
            // اليوم المحاسبي للمستند (02:00 → 02:00).
            $table->date('document_date');
            $table->string('notes', 1000)->nullable();
            $table->decimal('total_cost', 20, 6)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'document_date']);
            $table->index(['warehouse_id', 'document_date']);
            $table->index(['supplier_id', 'supplier_invoice_no']);
        });

        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_document_id')->constrained('stock_documents')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            // ما أدخله المستخدم بالوحدة المختارة؛ التحويل للوحدة الأساسية عند الترحيل (StockLedger).
            $table->foreignId('unit_id')->constrained('stock_units')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            // تكلفة الوحدة المختارة — للاستلام فقط. التحويل والهالك يخرجان بمتوسط التكلفة.
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->string('waste_reason', 30)->nullable();
            $table->string('notes', 500)->nullable();
            // قيمة السطر الفعلية بعد الترحيل (من الحركة المسجّلة).
            $table->decimal('posted_cost', 20, 6)->nullable();
            $table->timestamps();

            $table->unique(['stock_document_id', 'line_no']);
            $table->index('stock_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_document_lines');
        Schema::dropIfExists('stock_documents');
        Schema::dropIfExists('supplier_stock_item');
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_unit_id');
            $table->dropConstrainedForeignId('default_warehouse_id');
            $table->dropColumn(['name_en', 'last_cost']);
        });
    }
};
