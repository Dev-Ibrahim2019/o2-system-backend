<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * فاتورة المشتريات الموحّدة: purchase_bills هي المستند الأب، وترحيلها ينشئ استلاماً مخزنياً (GRN)
 * عبر StockDocumentService، وقيد ذمة المورّد عبر SupplierAccountingService، وسند صرف للمدفوع.
 *
 * كل الأعمدة اختيارية: الفواتير القديمة (is_inventory = false) تبقى كما هي، للقراءة.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'purchases.view',            // قائمة فواتير المشتريات وعرضها
        'purchases.create',          // إنشاء وتعديل وحذف المسودات
        'purchases.post',            // الترحيل (استلام + ذمة + قيد + سند)
        'purchases.reverse',         // عكس الفاتورة المرحّلة بكل آثارها
        'purchases.pay',             // تسديد المتبقي للمورّد
        'inventory.receipts.direct', // استلام مشتريات بدون فاتورة (الشاشة القديمة)
    ];

    private const STATUSES = "'draft','pending_approval','unpaid','partially_paid','paid','overdue','cancelled'";

    public function up(): void
    {
        Schema::table('purchase_bills', function (Blueprint $table) {
            $table->boolean('is_inventory')->default(false)->after('bill_number');
            $table->foreignId('warehouse_id')->nullable()->after('supplier_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('supplier_invoice_no', 64)->nullable()->after('warehouse_id');
            $table->decimal('exchange_rate', 18, 6)->default(1)->after('currency');
            $table->enum('payment_mode', ['cash', 'credit', 'partial'])->nullable()->after('exchange_rate');
            // المدفوع عند الترحيل ومن أي حساب (صندوق/بنك)
            $table->decimal('paid_on_post', 15, 4)->default(0)->after('paid_amount');
            $table->foreignId('cash_account_id')->nullable()->after('paid_on_post')->constrained('accounts')->nullOnDelete();
            // ضريبة مدخلات قابلة للخصم → لا تدخل تكلفة المخزون
            $table->boolean('tax_recoverable')->default(true)->after('tax_total');
            $table->foreignId('stock_document_id')->nullable()->after('journal_entry_id')->constrained('stock_documents')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable()->after('posted_by');
            $table->foreignId('reversed_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');
            $table->index(['is_inventory', 'status', 'bill_date']);
            $table->index(['supplier_id', 'supplier_invoice_no']);
        });
        DB::statement('ALTER TABLE purchase_bills MODIFY status ENUM('.self::STATUSES.",'reversed') NOT NULL DEFAULT 'draft'");

        Schema::table('purchase_bill_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('line_no')->nullable()->after('purchase_bill_id');
            // stock = صنف مخزني يدخل المخزن؛ expense = مصروف غير مخزني (نقل، تحميل...) للذمة والقيد فقط
            $table->enum('line_kind', ['stock', 'expense'])->default('expense')->after('line_no');
            $table->foreignId('stock_item_id')->nullable()->after('product_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->after('stock_item_id')->constrained('stock_units')->restrictOnDelete();
            $table->decimal('discount_pct', 7, 4)->default(0)->after('discount');
            $table->decimal('allocated_discount', 15, 4)->default(0)->after('discount_pct');
            $table->date('expiry_date')->nullable()->after('allocated_discount');
            // تكلفة الوحدة المختارة بالشيكل كما دخلت المخزن (بعد الخصمين، وبلا الضريبة القابلة للخصم)
            $table->decimal('stock_unit_cost', 18, 6)->nullable()->after('expiry_date');
        });

        Schema::table('stock_documents', function (Blueprint $table) {
            $table->foreignId('purchase_bill_id')->nullable()->after('supplier_invoice_no')->constrained('purchase_bills')->nullOnDelete();
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('purchase_bill_id')->nullable()->after('entity_name')->constrained('purchase_bills')->nullOnDelete();
            // قيد الدفعة في الدفتر العام (لعكسه مع الفاتورة)
            $table->unsignedBigInteger('transaction_id')->nullable()->after('purchase_bill_id');
        });

        Schema::table('inventory_settings', function (Blueprint $table) {
            // تحذير سطر فاتورة المشتريات إن زاد السعر عن آخر سعر للمورّد بأكثر من هذه النسبة
            $table->decimal('price_alert_percent', 5, 2)->default(10);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::where('name', 'super-admin')->first()?->givePermissionTo(self::PERMISSIONS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::whereIn('name', self::PERMISSIONS)->delete();

        Schema::table('inventory_settings', fn (Blueprint $t) => $t->dropColumn('price_alert_percent'));
        Schema::table('vouchers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('purchase_bill_id');
            $t->dropColumn('transaction_id');
        });
        Schema::table('stock_documents', fn (Blueprint $t) => $t->dropConstrainedForeignId('purchase_bill_id'));
        Schema::table('purchase_bill_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('stock_item_id');
            $t->dropConstrainedForeignId('unit_id');
            $t->dropColumn(['line_no', 'line_kind', 'discount_pct', 'allocated_discount', 'expiry_date', 'stock_unit_cost']);
        });

        DB::table('purchase_bills')->where('status', 'reversed')->update(['status' => 'cancelled']);
        DB::statement('ALTER TABLE purchase_bills MODIFY status ENUM('.self::STATUSES.") NOT NULL DEFAULT 'draft'");
        // MySQL استبدل فهرس مفتاح supplier_id الأجنبي بالفهرس المركّب؛ يُعاد قبل حذف المركّب وإلا يُرفض الحذف.
        Schema::table('purchase_bills', fn (Blueprint $t) => $t->index('supplier_id', 'purchase_bills_supplier_id_foreign'));
        Schema::table('purchase_bills', function (Blueprint $t) {
            $t->dropIndex(['is_inventory', 'status', 'bill_date']);
            $t->dropIndex(['supplier_id', 'supplier_invoice_no']);
            $t->dropConstrainedForeignId('warehouse_id');
            $t->dropConstrainedForeignId('cash_account_id');
            $t->dropConstrainedForeignId('stock_document_id');
            $t->dropConstrainedForeignId('posted_by');
            $t->dropConstrainedForeignId('reversed_by');
            $t->dropColumn(['is_inventory', 'supplier_invoice_no', 'exchange_rate', 'payment_mode', 'paid_on_post',
                'tax_recoverable', 'posted_at', 'reversed_at', 'reversal_reason']);
        });
    }
};
