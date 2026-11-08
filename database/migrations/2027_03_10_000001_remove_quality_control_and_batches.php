<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إلغاء قسم "الإنتاج والجودة" والدفعات نهائياً (قرار 2026-11-04):
 *
 * - تُحذف: جداول الدفعات وأرصدتها، قالب الأقسام، عدّاد الأسياخ، عمود الدفعة على الحركات وأسطر المستندات،
 *   أعمدة التفريك/الإنتاج/المعرض/الصلاحية، دور المخزن، تفصيل اللحوم في الجرد، إعدادات الجودة، "يتتبع الصلاحية".
 * - تبقى: الجهة وحالة الاستلام على مستند الصرف، ومستوى الوصول (مشاهدة/تعديل) على ربط المستخدم بالمخزن،
 *   ونوع الجرد (kind/business_day) حتى تبقى الجرود اليومية القديمة سجلات كما هي.
 *
 * لا تُحذف بيانات عمل: up() يرفض التنفيذ إن وُجدت دفعة أو مستند من الأنواع الملغاة أو قيمة في عمود سيُحذف.
 * down() يعيد البنية فارغة (القيم المحذوفة كانت فارغة أصلاً).
 */
return new class extends Migration
{
    private const DOC_TYPES = "'receipt','transfer','waste'";

    private const QUALITY_DOC_TYPES = "'receipt','transfer','waste','breakdown','production','skewer','showroom_sale'";

    /** أعمدة تُحذف من أسطر المستندات — كلها يجب أن تكون فارغة. */
    private const LINE_COLUMNS = ['direction', 'cartons', 'production_date', 'expiry_date', 'value_ratio', 'sale_price'];

    public function up(): void
    {
        $this->assertNothingToLose();

        // الـ Trigger يقرأ batch_id — يُستبدل قبل حذف العمود.
        $this->replaceMovementUpdateTrigger(withBatch: false);
        if (Schema::hasColumn('stock_movements', 'batch_id')) {
            Schema::table('stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('batch_id'));
        }

        if (Schema::hasColumn('stock_document_lines', 'batch_id')) {
            Schema::table('stock_document_lines', function (Blueprint $t) {
                $t->dropConstrainedForeignId('created_batch_id');
                $t->dropConstrainedForeignId('batch_id');
                $t->dropColumn([...self::LINE_COLUMNS, 'is_prime']);
            });
        }
        if (Schema::hasColumn('stock_documents', 'revenue')) {
            Schema::table('stock_documents', fn (Blueprint $t) => $t->dropColumn('revenue'));
        }
        DB::statement('ALTER TABLE stock_documents MODIFY type ENUM('.self::DOC_TYPES.') NOT NULL');

        Schema::dropIfExists('stock_batch_balances');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('department_branch_settings');
        Schema::dropIfExists('serial_counters');

        if (Schema::hasColumn('stock_count_lines', 'dissected_quantity')) {
            Schema::table('stock_count_lines', fn (Blueprint $t) => $t->dropColumn(['dissected_quantity', 'marinated_quantity']));
        }

        // دور المخزن: يعود القيد "قسم واحد ← مخزن واحد في الفرع". (لا تكرار في البيانات — يُفحص أعلاه.)
        if (Schema::hasColumn('warehouses', 'role')) {
            $indexes = collect(DB::select('SHOW INDEX FROM warehouses'))->pluck('Key_name')->unique();
            Schema::table('warehouses', function (Blueprint $t) use ($indexes) {
                if ($indexes->contains('warehouses_branch_department_role_unique')) {
                    $t->dropUnique('warehouses_branch_department_role_unique');
                }
                $t->dropColumn('role');
            });
            Schema::table('warehouses', fn (Blueprint $t) => $t->unique(['branch_id', 'department_id']));
        }

        if (Schema::hasColumn('inventory_settings', 'expiry_warning_days')) {
            Schema::table('inventory_settings', fn (Blueprint $t) => $t->dropColumn(['expiry_warning_days', 'expiry_critical_days', 'showroom_max_days', 'spice_grams_per_kg']));
        }
        if (Schema::hasColumn('stock_items', 'tracks_expiry')) {
            Schema::table('stock_items', fn (Blueprint $t) => $t->dropColumn('tracks_expiry'));
        }
        if (Schema::hasColumn('purchase_bill_items', 'expiry_date')) {
            Schema::table('purchase_bill_items', fn (Blueprint $t) => $t->dropColumn('expiry_date'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('purchase_bill_items', 'expiry_date')) {
            Schema::table('purchase_bill_items', fn (Blueprint $t) => $t->date('expiry_date')->nullable()->after('allocated_discount'));
        }
        if (! Schema::hasColumn('stock_items', 'tracks_expiry')) {
            Schema::table('stock_items', fn (Blueprint $t) => $t->boolean('tracks_expiry')->default(false));
        }
        if (! Schema::hasColumn('inventory_settings', 'expiry_warning_days')) {
            Schema::table('inventory_settings', function (Blueprint $t) {
                $t->unsignedSmallInteger('expiry_warning_days')->default(90);
                $t->unsignedSmallInteger('expiry_critical_days')->default(30);
                $t->unsignedSmallInteger('showroom_max_days')->default(3);
                $t->decimal('spice_grams_per_kg', 10, 3)->default(30);
            });
        }
        if (! Schema::hasColumn('warehouses', 'role')) {
            Schema::table('warehouses', function (Blueprint $t) {
                $t->enum('role', ['department', 'factory_raw', 'factory_products', 'showroom'])->default('department')->after('department_id');
            });
            Schema::table('warehouses', function (Blueprint $t) {
                $t->dropUnique(['branch_id', 'department_id']);
                $t->unique(['branch_id', 'department_id', 'role'], 'warehouses_branch_department_role_unique');
            });
        }
        if (! Schema::hasColumn('stock_count_lines', 'dissected_quantity')) {
            Schema::table('stock_count_lines', function (Blueprint $t) {
                $t->decimal('dissected_quantity', 18, 6)->nullable()->after('counted_quantity');
                $t->decimal('marinated_quantity', 18, 6)->nullable()->after('dissected_quantity');
            });
        }

        if (! Schema::hasTable('serial_counters')) {
            Schema::create('serial_counters', function (Blueprint $t) {
                $t->string('name', 50)->primary();
                $t->unsignedBigInteger('value')->default(0);
            });
        }
        if (! Schema::hasTable('department_branch_settings')) {
            Schema::create('department_branch_settings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
                $t->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $t->boolean('has_meals')->default(true);
                $t->boolean('has_factory')->default(false);
                $t->boolean('has_showroom')->default(false);
                $t->boolean('is_shawarma')->default(false);
                $t->foreignId('meat_category_id')->nullable()->constrained('stock_categories')->nullOnDelete();
                $t->foreignId('spice_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
                $t->foreignId('skewer_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
                $t->timestamps();
                $t->unique(['department_id', 'branch_id']);
            });
        }
        if (! Schema::hasTable('stock_batches')) {
            Schema::create('stock_batches', function (Blueprint $t) {
                $t->id();
                $t->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
                $t->string('code', 80)->unique();
                $t->unsignedInteger('sequence');
                $t->string('source', 20);
                $t->foreignId('stock_document_id')->nullable()->constrained('stock_documents')->nullOnDelete();
                $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
                $t->decimal('unit_cost', 18, 6)->default(0);
                $t->decimal('quantity', 18, 6);
                $t->decimal('cartons', 12, 2)->nullable();
                $t->date('production_date')->nullable();
                $t->date('expiry_date')->nullable();
                $t->string('notes', 500)->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['stock_item_id', 'sequence']);
                $t->index(['stock_item_id', 'expiry_date']);
            });
        }
        if (! Schema::hasTable('stock_batch_balances')) {
            Schema::create('stock_batch_balances', function (Blueprint $t) {
                $t->id();
                $t->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
                $t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
                $t->decimal('quantity', 18, 6)->default(0);
                $t->timestamp('arrived_at')->nullable();
                $t->timestamp('updated_at')->nullable();
                $t->unique(['batch_id', 'warehouse_id']);
                $t->index(['warehouse_id', 'quantity']);
            });
        }

        DB::statement('ALTER TABLE stock_documents MODIFY type ENUM('.self::QUALITY_DOC_TYPES.') NOT NULL');
        if (! Schema::hasColumn('stock_documents', 'revenue')) {
            Schema::table('stock_documents', fn (Blueprint $t) => $t->decimal('revenue', 20, 6)->nullable()->after('total_cost'));
        }
        if (! Schema::hasColumn('stock_document_lines', 'batch_id')) {
            Schema::table('stock_document_lines', function (Blueprint $t) {
                $t->foreignId('batch_id')->nullable()->after('stock_item_id')->constrained('stock_batches')->restrictOnDelete();
                $t->enum('direction', ['in', 'out'])->nullable()->after('line_no');
                $t->decimal('cartons', 12, 2)->nullable()->after('quantity');
                $t->date('production_date')->nullable();
                $t->date('expiry_date')->nullable();
                $t->decimal('value_ratio', 10, 4)->nullable();
                $t->boolean('is_prime')->default(false);
                $t->decimal('sale_price', 18, 6)->nullable();
                $t->foreignId('created_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('stock_movements', 'batch_id')) {
            Schema::table('stock_movements', function (Blueprint $t) {
                $t->foreignId('batch_id')->nullable()->after('stock_item_id')->constrained('stock_batches')->restrictOnDelete();
            });
        }
        $this->replaceMovementUpdateTrigger(withBatch: true);
    }

    /** يرفض الإلغاء إن كان سيُفقد أي بيانات عمل (الإلغاء لم يُصمَّم لحذف سجلات). */
    private function assertNothingToLose(): void
    {
        $problems = [];
        foreach (['stock_batches', 'stock_batch_balances', 'serial_counters'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                $problems[] = "{$table} ليس فارغاً";
            }
        }
        if (Schema::hasColumn('stock_movements', 'batch_id') && DB::table('stock_movements')->whereNotNull('batch_id')->exists()) {
            $problems[] = 'حركات مربوطة بدفعات';
        }
        $legacy = DB::table('stock_documents')->whereNotIn('type', ['receipt', 'transfer', 'waste'])->count();
        if ($legacy > 0) {
            $problems[] = "{$legacy} مستند من أنواع الجودة الملغاة";
        }
        if (Schema::hasColumn('stock_document_lines', 'batch_id')) {
            foreach ([...self::LINE_COLUMNS, 'batch_id', 'created_batch_id'] as $column) {
                if (DB::table('stock_document_lines')->whereNotNull($column)->exists()) {
                    $problems[] = "stock_document_lines.{$column} فيه قيم";
                }
            }
        }
        if (Schema::hasColumn('stock_documents', 'revenue') && DB::table('stock_documents')->whereNotNull('revenue')->exists()) {
            $problems[] = 'stock_documents.revenue فيه قيم';
        }
        if (Schema::hasColumn('stock_count_lines', 'dissected_quantity')
            && DB::table('stock_count_lines')->where(fn ($q) => $q->whereNotNull('dissected_quantity')->orWhereNotNull('marinated_quantity'))->exists()) {
            $problems[] = 'تفصيل اللحوم في الجرد فيه قيم';
        }
        if (Schema::hasColumn('purchase_bill_items', 'expiry_date') && DB::table('purchase_bill_items')->whereNotNull('expiry_date')->exists()) {
            $problems[] = 'تواريخ صلاحية على أسطر فواتير المشتريات';
        }
        if (Schema::hasColumn('warehouses', 'role')) {
            $dup = DB::table('warehouses')->whereNotNull('department_id')
                ->select('branch_id', 'department_id')->groupBy('branch_id', 'department_id')->havingRaw('COUNT(*) > 1')->exists();
            if ($dup) {
                $problems[] = 'قسم مربوط بأكثر من مخزن في الفرع نفسه (أدوار مصنع/معرض)';
            }
        }

        if ($problems !== []) {
            throw new RuntimeException('لا يمكن إلغاء الجودة والدفعات دون فقد بيانات: '.implode('، ', $problems).'.');
        }
    }

    /** حماية الحركة المرحّلة من التعديل (لا يُغيَّر إلا accounting_posted_at) — مع عمود الدفعة أو بدونه. */
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
