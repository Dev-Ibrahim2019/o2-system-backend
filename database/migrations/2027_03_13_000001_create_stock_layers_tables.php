<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تكلفة المخزون بالدفعات (Cost Layers) بدل المتوسط المرجّح.
 *
 * - stock_layers: كل وارد ينشئ دفعة بكميتها المتبقية وسعرها (لكل وحدة أساسية). القيد CHECK يمنع المتبقي السالب
 *   أو الأكبر من الوارد، والعمود المحسوب is_open يخدم فهرس FIFO (MySQL لا يدعم الفهرس الجزئي).
 * - stock_layer_allocations: كل خروج مربوط بالدفعات التي خرج منها، بسعرها لحظة الخروج. لا تُعدَّل ولا تُحذف
 *   (Trigger)؛ العكس سطر عكسي يعيد الكمية لنفس الدفعة.
 * - stock_layer_shortages: النقص المعلّق (خروج بلا دفعات كافية، كالبيع) بتكلفة مؤقتة تُسوّى عند وصول دفعة.
 * - stock_layer_revaluations: فروق التكلفة (تسوية سعر، تسوية نقص) مربوطة بالدفعة والتخصيص وحركة التصحيح.
 * - stock_draft_allocations: توزيع المستخدم على الدفعات في المسودة (صرف/هالك/مرتجع/جرد) حتى الترحيل.
 * - stock_cost_adjustments(+lines): مستند "تسوية سعر" لفاتورة صُرف منها.
 * - stock_movements.adjusts_movement_id: حركة تصحيح تكلفة (كمية صفر، قيمة فقط) لحركة سابقة؛ والنوع revaluation
 *   لإعادة التقييم. الـ Triggers تُستبدل: الكمية صفر مسموحة لهذين فقط، والعمود الجديد ثابت كبقية الحركة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('adjusts_movement_id')->nullable()->after('reversal_of_id')->constrained('stock_movements')->restrictOnDelete();
        });
        $this->movementTriggers(withLayers: true);

        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            // purchase_invoice | receipt | opening_balance | transfer_in | adjustment_in | production_in | sales_return | split
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            // حركة الوارد التي أنشأت الدفعة (فارغة لدفعات ترحيل البيانات).
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            // الدفعة الأصلية (فاتورة/أول مدة) عبر التحويلات، فيبقى مرجع الفاتورة ظاهراً.
            $table->foreignId('origin_layer_id')->nullable()->constrained('stock_layers')->restrictOnDelete();
            // التخصيص في المخزن المصدر الذي جاءت منه (التحويل / التقسيم)؛ FK يضاف بعد جدول التخصيصات.
            $table->unsignedBigInteger('source_allocation_id')->nullable();
            $table->foreignId('purchase_bill_id')->nullable()->constrained('purchase_bills')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->dateTime('received_at');
            $table->decimal('qty_in', 18, 6);
            $table->decimal('qty_remaining', 18, 6);
            $table->decimal('unit_cost', 18, 6);
            // دفعة بتكلفة مؤقتة (وصلت بتحويل جزء غير مغطى) — تُصحَّح عند تسوية نقص المصدر.
            $table->boolean('is_provisional')->default(false);
            $table->unsignedBigInteger('shortage_id')->nullable();
            $table->boolean('is_open')->storedAs('qty_remaining > 0');
            $table->timestamps();

            $table->index(['warehouse_id', 'stock_item_id', 'is_open', 'received_at', 'id'], 'stock_layers_fifo_index');
            $table->index('purchase_bill_id');
            $table->index(['source_type', 'source_id']);
            $table->index('shortage_id');
        });
        DB::statement('ALTER TABLE stock_layers ADD CONSTRAINT stock_layers_quantities CHECK (qty_in > 0 AND qty_remaining >= 0 AND qty_remaining <= qty_in AND unit_cost >= 0)');

        Schema::create('stock_layer_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layer_id')->constrained('stock_layers')->restrictOnDelete();
            $table->foreignId('movement_id')->constrained('stock_movements')->restrictOnDelete();
            // نوع الحركة (sale, transfer_out, waste, adjustment, purchase_return, ...).
            $table->string('movement_type', 30);
            // سطر المستند أو سطر الطلب أو سطر الجرد الذي خرجت له الكمية.
            $table->unsignedBigInteger('movement_line_id')->nullable();
            // consume = خروج عادي، settle = تسوية نقص معلّق، restore = عكس خروج، void = إلغاء دفعة عند عكس واردها،
            // split = تقسيم دفعة مؤقتة عند تصحيح جزء منها.
            $table->string('kind', 12);
            // بإشارة: موجب = خرج من الدفعة، سالب = عاد إليها (restore).
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('total_cost', 20, 6);
            $table->foreignId('reverses_allocation_id')->nullable()->unique()->constrained('stock_layer_allocations')->restrictOnDelete();
            $table->unsignedBigInteger('shortage_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('layer_id');
            $table->index('movement_id');
        });
        Schema::table('stock_layers', function (Blueprint $table) {
            $table->foreign('source_allocation_id')->references('id')->on('stock_layer_allocations')->restrictOnDelete();
        });

        Schema::create('stock_layer_shortages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->string('movement_type', 30);
            $table->unsignedBigInteger('movement_line_id')->nullable();
            $table->decimal('quantity', 18, 6);
            $table->decimal('qty_open', 18, 6);
            $table->decimal('provisional_unit_cost', 18, 6);
            // open | settled | cancelled
            $table->string('status', 12)->default('open');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'stock_item_id', 'status', 'id'], 'stock_layer_shortages_open_index');
            $table->index('movement_id');
        });
        DB::statement('ALTER TABLE stock_layer_shortages ADD CONSTRAINT stock_layer_shortages_quantities CHECK (quantity > 0 AND qty_open >= 0 AND qty_open <= quantity)');

        Schema::create('stock_layer_revaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('layer_id')->constrained('stock_layers')->restrictOnDelete();
            // التخصيص المصحَّح (الجزء المصروف)؛ فارغ = الجزء الوارد/المتبقي في الدفعة نفسها.
            $table->foreignId('allocation_id')->nullable()->constrained('stock_layer_allocations')->restrictOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('old_unit_cost', 18, 6);
            $table->decimal('new_unit_cost', 18, 6);
            $table->decimal('value', 20, 6);
            // cost_adjustment | shortage_settlement
            $table->string('reason', 30);
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('layer_id');
            $table->index('allocation_id');
        });

        Schema::create('stock_draft_allocations', function (Blueprint $table) {
            $table->id();
            // stock_document_line | stock_count_line
            $table->string('owner_type', 30);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('layer_id')->constrained('stock_layers')->restrictOnDelete();
            // بالوحدة الأساسية للصنف.
            $table->decimal('quantity', 18, 6);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('stock_cost_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->foreignId('purchase_bill_id')->nullable()->constrained('purchase_bills')->nullOnDelete();
            $table->string('reason', 500);
            $table->decimal('total_value', 20, 6)->default(0);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_cost_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_cost_adjustment_id')->constrained('stock_cost_adjustments')->cascadeOnDelete();
            $table->foreignId('layer_id')->constrained('stock_layers')->restrictOnDelete();
            $table->decimal('old_unit_cost', 18, 6);
            $table->decimal('new_unit_cost', 18, 6);
            // الكمية التي يشملها الفرق = الوارد − المرتجع للمورّد.
            $table->decimal('quantity', 18, 6);
            $table->decimal('remaining_quantity', 18, 6);
            $table->decimal('value', 20, 6);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE stock_documents MODIFY type ENUM('receipt','transfer','waste','purchase_return') NOT NULL");
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('transaction_id')->nullable()->after('purchase_bill_id');
        });
        Schema::table('stock_document_lines', function (Blueprint $table) {
            $table->foreignId('purchase_bill_item_id')->nullable()->after('stock_item_id')->constrained('purchase_bill_items')->nullOnDelete();
        });
        Schema::table('purchase_bill_items', function (Blueprint $table) {
            // سطر مصاريف (نقل، جمارك...) يُحمَّل على تكلفة الأصناف بنسبة قيمتها.
            $table->boolean('allocate_to_stock')->default(false)->after('line_kind');
            $table->decimal('allocated_expense', 15, 4)->default(0)->after('allocated_discount');
        });
        Schema::table('stock_count_lines', function (Blueprint $table) {
            // سعر الزيادة الذي يدخله المستخدم؛ فارغ = سعر آخر دفعة للصنف.
            $table->decimal('entered_unit_cost', 18, 6)->nullable()->after('counted_quantity');
        });

        foreach (['stock_layer_allocations' => ['stock_layer_alloc_bd', 'allocations are immutable; post a reversal instead'],
            'stock_layers' => ['stock_layers_bd', 'layers cannot be deleted']] as $table => [$trigger, $message]) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            DB::unprepared("CREATE TRIGGER {$trigger} BEFORE DELETE ON {$table} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LAYERS: {$message}'; END");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS stock_layer_alloc_bu');
        DB::unprepared("CREATE TRIGGER stock_layer_alloc_bu BEFORE UPDATE ON stock_layer_allocations FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LAYERS: allocations are immutable; post a reversal instead'; END");
    }

    public function down(): void
    {
        foreach (['stock_layer_alloc_bd', 'stock_layer_alloc_bu', 'stock_layers_bd'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::table('stock_count_lines', fn (Blueprint $t) => $t->dropColumn('entered_unit_cost'));
        Schema::table('purchase_bill_items', fn (Blueprint $t) => $t->dropColumn(['allocate_to_stock', 'allocated_expense']));
        Schema::table('stock_document_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('purchase_bill_item_id'));
        Schema::table('stock_documents', fn (Blueprint $t) => $t->dropColumn('transaction_id'));
        DB::table('stock_documents')->where('type', 'purchase_return')->delete();
        DB::statement("ALTER TABLE stock_documents MODIFY type ENUM('receipt','transfer','waste') NOT NULL");

        Schema::dropIfExists('stock_cost_adjustment_lines');
        Schema::dropIfExists('stock_cost_adjustments');
        Schema::dropIfExists('stock_draft_allocations');
        Schema::dropIfExists('stock_layer_revaluations');
        Schema::dropIfExists('stock_layer_shortages');
        Schema::table('stock_layers', fn (Blueprint $t) => $t->dropForeign(['source_allocation_id']));
        Schema::dropIfExists('stock_layer_allocations');
        Schema::dropIfExists('stock_layers');

        // حركات التصحيح (كمية صفر) تبقى في الدفتر — الحركات لا تُحذف — والقيمة متسقة معها.
        $this->movementTriggers(withLayers: false);
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('adjusts_movement_id'));
    }

    /** Triggers الحركات: الإدخال (الكمية والرصيد) والتعديل (الحركة ثابتة). */
    private function movementTriggers(bool $withLayers): void
    {
        $zeroRejected = $withLayers
            ? "NEW.quantity = 0 AND NOT ((NEW.adjusts_movement_id IS NOT NULL OR NEW.type = 'revaluation') AND NEW.total_cost <> 0)"
            : "NEW.quantity = 0 AND NEW.type <> 'revaluation'";
        $adjusts = $withLayers ? 'AND NEW.adjusts_movement_id <=> OLD.adjusts_movement_id' : '';

        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_bi');
        DB::unprepared(<<<SQL
            CREATE TRIGGER stock_movements_bi BEFORE INSERT ON stock_movements FOR EACH ROW
            BEGIN
                IF {$zeroRejected} THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: movement quantity cannot be zero';
                END IF;
                IF NEW.balance_after <> COALESCE((SELECT b.quantity FROM stock_balances b
                        WHERE b.warehouse_id = NEW.warehouse_id AND b.stock_item_id = NEW.stock_item_id), 0) + NEW.quantity THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: balance_after does not follow the current balance';
                END IF;
            END
        SQL);

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
                    AND NEW.created_at <=> OLD.created_at {$adjusts}
                    AND (NEW.created_by <=> OLD.created_by OR NEW.created_by IS NULL)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: posted movements are immutable; post a reversal instead';
                END IF;
            END
        SQL);
    }
};
