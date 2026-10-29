<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * نقل منطق "O2 لإدارة الجودة والرقابة" فوق محرك المخزون الموجود (إضافة فقط، لا حذف بيانات):
 *
 * - الدفعات: stock_batches (كودها = كود الصنف + تسلسل) ورصيد كل دفعة في كل مخزن stock_batch_balances.
 *   الحركة تحمل batch_id، ورصيد الدفعة يُحدَّث داخل معاملة الحركة نفسها (StockLedger).
 * - مستندات جديدة على stock_documents: تفريك، إنتاج مصنع، أسياخ شاورما، مبيعات معرض.
 * - الجرد اليومي للقسم (kind = daily بيوم محدد) وتفصيل اللحوم (مشرّح / مبهّر).
 * - دور المخزن (قسم / مواد خام مصنع / منتجات مصنع / معرض) وإعداد القسم في كل فرع (قالب واحد).
 * - مستوى الوصول (مشاهدة / تعديل) على ربط المستخدم بالمخزن الموجود — بلا نظام صلاحيات موازٍ.
 */
return new class extends Migration
{
    private const OLD_DOC_TYPES = "'receipt','transfer','waste'";

    private const NEW_DOC_TYPES = "'receipt','transfer','waste','breakdown','production','skewer','showroom_sale'";

    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            // الكود = كود الصنف + التسلسل (X1 → X11، X12...)، والأسياخ بتسلسل عام لا يتكرر.
            $table->string('code', 80)->unique();
            $table->unsignedInteger('sequence');
            $table->string('source', 20); // receipt | breakdown | production | skewer
            $table->foreignId('stock_document_id')->nullable()->constrained('stock_documents')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            // سعر الوحدة الأساسية عند الدخول (للعرض والمقارنة؛ التكلفة المحاسبية متوسط مرجّح).
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('quantity', 18, 6); // الكمية الأصلية بالوحدة الأساسية
            $table->decimal('cartons', 12, 2)->nullable();
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['stock_item_id', 'sequence']);
            $table->index(['stock_item_id', 'expiry_date']);
        });

        Schema::create('stock_batch_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 18, 6)->default(0);
            // أول وصول للدفعة لهذا المخزن — أساس "مدة التخزين" (المعرض: تنبيه بعد X أيام).
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['batch_id', 'warehouse_id']);
            $table->index(['warehouse_id', 'quantity']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('stock_item_id')->constrained('stock_batches')->restrictOnDelete();
        });
        $this->replaceMovementUpdateTrigger(withBatch: true);

        DB::statement('ALTER TABLE stock_documents MODIFY type ENUM('.self::NEW_DOC_TYPES.') NOT NULL');
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->string('destination', 150)->nullable()->after('to_warehouse_id');          // "الجهة" في الصرف
            $table->enum('delivery_status', ['delivered', 'undelivered'])->nullable()->after('destination');
            $table->decimal('revenue', 20, 6)->nullable()->after('total_cost');              // مبيعات المعرض
        });
        Schema::table('stock_document_lines', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('stock_item_id')->constrained('stock_batches')->restrictOnDelete();
            $table->enum('direction', ['in', 'out'])->nullable()->after('line_no');           // التفريك: داخل / ناتج
            $table->decimal('cartons', 12, 2)->nullable()->after('quantity');
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('value_ratio', 10, 4)->nullable();                                 // وزن قيمة الناتج في التفريك
            $table->boolean('is_prime')->default(false);                                       // صدر/فخذ في نسبة التفريك
            $table->decimal('sale_price', 18, 6)->nullable();                                  // سعر الكيلو في مبيعات المعرض
            $table->foreignId('created_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
        });

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->enum('kind', ['full', 'daily'])->default('full')->after('warehouse_id');
            // الجرد اليومي للقسم: اليوم المحاسبي الذي يُقفل (قد يُدخل بعد انتهائه).
            $table->date('business_day')->nullable()->after('kind');
            $table->unique(['warehouse_id', 'kind', 'business_day']);
        });
        Schema::table('stock_count_lines', function (Blueprint $table) {
            // تفصيل الباقي من اللحوم (قسم الشاورما): بدون تشريح = المعدود − مشرّح − مبهّر
            $table->decimal('dissected_quantity', 18, 6)->nullable()->after('counted_quantity');
            $table->decimal('marinated_quantity', 18, 6)->nullable()->after('dissected_quantity');
        });

        // دور المخزن: القسم الواحد في الفرع قد يملك مخزن مواد مصنع ومخزن منتجات ومعرضاً.
        $indexes = collect(DB::select('SHOW INDEX FROM warehouses'))->pluck('Key_name')->unique();
        Schema::table('warehouses', function (Blueprint $table) use ($indexes) {
            $table->enum('role', ['department', 'factory_raw', 'factory_products', 'showroom'])->default('department')->after('department_id');
            // فهرس عادي لمفتاح الفرع قبل إسقاط الفريد القديم (MySQL كان يستخدمه للمفتاح الأجنبي).
            if (! $indexes->contains('warehouses_branch_id_index')) {
                $table->index('branch_id', 'warehouses_branch_id_index');
            }
        });
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique('warehouses_branch_id_department_id_unique');
            $table->unique(['branch_id', 'department_id', 'role'], 'warehouses_branch_department_role_unique');
        });

        Schema::table('warehouse_user', function (Blueprint $table) {
            $table->enum('access_level', ['view', 'edit'])->default('edit')->after('user_id');
        });

        Schema::create('department_branch_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->boolean('has_meals')->default(true);
            $table->boolean('has_factory')->default(false);
            $table->boolean('has_showroom')->default(false);
            $table->boolean('is_shawarma')->default(false);
            // الشاورما: تصنيف اللحوم وصنف البهار وصنف السيخ.
            $table->foreignId('meat_category_id')->nullable()->constrained('stock_categories')->nullOnDelete();
            $table->foreignId('spice_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->foreignId('skewer_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'branch_id']);
        });

        Schema::table('inventory_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('expiry_warning_days')->default(90);
            $table->unsignedSmallInteger('expiry_critical_days')->default(30);
            $table->unsignedSmallInteger('showroom_max_days')->default(3);
            // البهار المفترض لكل كغ لحم مستهلك: (اللحم ÷ 50) × 1.5 = 30 غ لكل كغ.
            $table->decimal('spice_grams_per_kg', 10, 3)->default(30);
        });

        Schema::create('serial_counters', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_counters');
        if (Schema::hasColumn('inventory_settings', 'expiry_warning_days')) {
            Schema::table('inventory_settings', fn (Blueprint $t) => $t->dropColumn(['expiry_warning_days', 'expiry_critical_days', 'showroom_max_days', 'spice_grams_per_kg']));
        }
        Schema::dropIfExists('department_branch_settings');
        if (Schema::hasColumn('warehouse_user', 'access_level')) {
            Schema::table('warehouse_user', fn (Blueprint $t) => $t->dropColumn('access_level'));
        }
        $indexes = collect(DB::select('SHOW INDEX FROM warehouses'))->pluck('Key_name')->unique();
        Schema::table('warehouses', function (Blueprint $table) use ($indexes) {
            if ($indexes->contains('warehouses_branch_department_role_unique')) {
                $table->dropUnique('warehouses_branch_department_role_unique');
            }
            if (! $indexes->contains('warehouses_branch_id_department_id_unique')) {
                $table->unique(['branch_id', 'department_id']);
            }
        });
        if (Schema::hasColumn('warehouses', 'role')) {
            Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn('role'));
        }
        if (Schema::hasColumn('stock_count_lines', 'dissected_quantity')) {
            Schema::table('stock_count_lines', fn (Blueprint $t) => $t->dropColumn(['dissected_quantity', 'marinated_quantity']));
        }
        if (Schema::hasColumn('stock_counts', 'kind')) {
            Schema::table('stock_counts', function (Blueprint $t) {
                $t->dropUnique(['warehouse_id', 'kind', 'business_day']);
                $t->dropColumn(['kind', 'business_day']);
            });
        }
        if (Schema::hasColumn('stock_document_lines', 'batch_id')) {
            Schema::table('stock_document_lines', function (Blueprint $t) {
                $t->dropConstrainedForeignId('created_batch_id');
                $t->dropConstrainedForeignId('batch_id');
                $t->dropColumn(['direction', 'cartons', 'production_date', 'expiry_date', 'value_ratio', 'is_prime', 'sale_price']);
            });
        }
        if (Schema::hasColumn('stock_documents', 'destination')) {
            Schema::table('stock_documents', fn (Blueprint $t) => $t->dropColumn(['destination', 'delivery_status', 'revenue']));
        }
        // يفشل عمداً إن وُجدت مستندات من الأنواع الجديدة — لا تُحذف بيانات.
        DB::statement('ALTER TABLE stock_documents MODIFY type ENUM('.self::OLD_DOC_TYPES.') NOT NULL');
        $this->replaceMovementUpdateTrigger(withBatch: false);
        if (Schema::hasColumn('stock_movements', 'batch_id')) {
            Schema::table('stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('batch_id'));
        }
        Schema::dropIfExists('stock_batch_balances');
        Schema::dropIfExists('stock_batches');
    }

    /** حماية الحركة المرحّلة من التعديل تشمل عمود الدفعة الجديد (لا يُغيَّر إلا accounting_posted_at). */
    private function replaceMovementUpdateTrigger(bool $withBatch): void
    {
        $batch = $withBatch ? 'AND NEW.batch_id <=> OLD.batch_id' : '';
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_bu');
        DB::unprepared(<<<SQL
            CREATE TRIGGER stock_movements_bu BEFORE UPDATE ON stock_movements FOR EACH ROW
            BEGIN
                IF NOT (NEW.id <=> OLD.id AND NEW.warehouse_id <=> OLD.warehouse_id AND NEW.stock_item_id <=> OLD.stock_item_id
                    AND NEW.type <=> OLD.type AND NEW.quantity <=> OLD.quantity AND NEW.unit_cost <=> OLD.unit_cost
                    AND NEW.total_cost <=> OLD.total_cost AND NEW.balance_after <=> OLD.balance_after
                    AND NEW.avg_cost_after <=> OLD.avg_cost_after AND NEW.entered_unit_id <=> OLD.entered_unit_id
                    AND NEW.entered_quantity <=> OLD.entered_quantity AND NEW.occurred_at <=> OLD.occurred_at
                    AND NEW.accounting_day <=> OLD.accounting_day AND NEW.reversal_of_id <=> OLD.reversal_of_id
                    AND NEW.source_type <=> OLD.source_type AND NEW.source_id <=> OLD.source_id
                    AND NEW.idempotency_key <=> OLD.idempotency_key AND NEW.notes <=> OLD.notes
                    AND NEW.created_at <=> OLD.created_at {$batch}
                    AND (NEW.created_by <=> OLD.created_by OR NEW.created_by IS NULL)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: posted movements are immutable; post a reversal instead';
                END IF;
            END
        SQL);
    }
};
