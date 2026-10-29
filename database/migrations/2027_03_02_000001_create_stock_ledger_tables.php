<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 2: محرك المخزون — الحركات والأرصدة وإعدادات المخزون.
 *
 * حماية القاعدة 1 و2 في قاعدة البيانات نفسها (Triggers)، لا في الكود فقط:
 *  - stock_balances: لا إدخال ولا تعديل إلا إذا أشار السطر إلى أحدث حركة مسجّلة للصنف في
 *    المخزن، وكان الرصيد والمتوسط مطابقين لما سجّلته تلك الحركة. الحذف ممنوع.
 *    → تعديل الرصيد مباشرة (UPDATE ... SET quantity = ...) يُرفض دائماً.
 *  - stock_movements: الحركة المرحّلة لا تُعدَّل ولا تُحذف، باستثناء حقل "مرحّل محاسبياً"
 *    (القرار 13). وعند الإدخال يجب أن يساوي "الرصيد بعد الحركة" الرصيدَ الحالي + كمية الحركة،
 *    فلا تدخل حركة تكسر تسلسل الرصيد.
 *
 * ملاحظة نشر: مع تفعيل binlog وlog_bin_trust_function_creators=0، يحتاج مستخدم القاعدة
 * الذي يشغّل الـ Migration صلاحية TRIGGER (وقد يحتاج SUPER) لإنشاء الـ Triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_settings', function (Blueprint $table) {
            $table->id();
            // الرصيد السالب ممنوع افتراضياً (المرحلة 2). خصم البيع في المرحلة 5 لا يُمنع أبداً على الكاشير.
            $table->boolean('allow_negative_stock')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('inventory_settings')->insert(['allow_negative_stock' => false, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->string('type', 30);
            // كل الكميات بالوحدة الأساسية للصنف (القاعدة 5). موجبة = وارد، سالبة = صادر.
            $table->decimal('quantity', 18, 6);
            // التكلفة لكل وحدة أساسية بالشيكل، وقيمة الحركة = الكمية × تكلفة الوحدة (بإشارة الكمية).
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('total_cost', 20, 6);
            // لقطة الرصيد والمتوسط بعد الحركة — أساس سجل حركة الصنف وحماية الأرصدة.
            $table->decimal('balance_after', 18, 6);
            $table->decimal('avg_cost_after', 18, 6);
            // ما أدخله المستخدم قبل التحويل للوحدة الأساسية (للمراجعة فقط).
            $table->foreignId('entered_unit_id')->nullable()->constrained('stock_units')->restrictOnDelete();
            $table->decimal('entered_quantity', 18, 6)->nullable();
            $table->dateTime('occurred_at');
            $table->date('accounting_day');
            // الحركة العكسية: نفس نوع الأصلية وكميتها معكوسة وتكلفتها نفسها. عكس واحد فقط لكل حركة.
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            // المستند المصدر (أمر شراء، طلب، جرد...) — يُملأ في المراحل التالية.
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            // منع التكرار عند إعادة الإرسال (القاعدة 4): المفتاح نفسه يُسجَّل مرة واحدة فقط.
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            // القرار 13: لا قيود محاسبية في هذا الإصدار — يبقى فارغاً تمهيداً للربط لاحقاً.
            $table->timestamp('accounting_posted_at')->nullable();

            $table->index(['stock_item_id', 'warehouse_id', 'id'], 'stock_movements_kardex_index');
            $table->index(['warehouse_id', 'accounting_day']);
            $table->index(['accounting_day', 'type']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('avg_cost', 18, 6)->default(0);
            $table->foreignId('last_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['warehouse_id', 'stock_item_id']);
        });

        $this->createTriggers();
    }

    public function down(): void
    {
        foreach (['stock_balances_bi', 'stock_balances_bu', 'stock_balances_bd', 'stock_movements_bi', 'stock_movements_bu', 'stock_movements_bd'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_settings');
    }

    private function createTriggers(): void
    {
        // ── الحركات ─────────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_movements_bi BEFORE INSERT ON stock_movements FOR EACH ROW
            BEGIN
                IF NEW.quantity = 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: movement quantity cannot be zero';
                END IF;
                -- الرصيد بعد الحركة = الرصيد الحالي + كمية الحركة
                IF NEW.balance_after <> COALESCE((SELECT b.quantity FROM stock_balances b
                        WHERE b.warehouse_id = NEW.warehouse_id AND b.stock_item_id = NEW.stock_item_id), 0) + NEW.quantity THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: balance_after does not follow the current balance';
                END IF;
            END
        SQL);

        // created_by يُسمح أن يصبح NULL فقط (حذف المستخدم عبر ON DELETE SET NULL).
        DB::unprepared(<<<'SQL'
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
                    AND NEW.created_at <=> OLD.created_at
                    AND (NEW.created_by <=> OLD.created_by OR NEW.created_by IS NULL)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: posted movements are immutable; post a reversal instead';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_movements_bd BEFORE DELETE ON stock_movements FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: posted movements cannot be deleted; post a reversal instead';
            END
        SQL);

        // ── الأرصدة ─────────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_balances_bi BEFORE INSERT ON stock_balances FOR EACH ROW
            BEGIN
                IF NEW.last_movement_id IS NULL THEN
                    IF NEW.quantity <> 0 OR NEW.avg_cost <> 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: a balance can only change through a recorded movement';
                    END IF;
                ELSEIF NOT EXISTS (SELECT 1 FROM stock_movements m
                        WHERE m.id = NEW.last_movement_id AND m.warehouse_id = NEW.warehouse_id
                          AND m.stock_item_id = NEW.stock_item_id
                          AND m.balance_after = NEW.quantity AND m.avg_cost_after = NEW.avg_cost) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: a balance can only change through a recorded movement';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_balances_bu BEFORE UPDATE ON stock_balances FOR EACH ROW
            BEGIN
                IF NEW.warehouse_id <> OLD.warehouse_id OR NEW.stock_item_id <> OLD.stock_item_id
                    OR NEW.last_movement_id IS NULL
                    OR (OLD.last_movement_id IS NOT NULL AND NEW.last_movement_id <= OLD.last_movement_id)
                    OR NOT EXISTS (SELECT 1 FROM stock_movements m
                        WHERE m.id = NEW.last_movement_id AND m.warehouse_id = NEW.warehouse_id
                          AND m.stock_item_id = NEW.stock_item_id
                          AND m.balance_after = NEW.quantity AND m.avg_cost_after = NEW.avg_cost)
                    OR EXISTS (SELECT 1 FROM stock_movements m2
                        WHERE m2.warehouse_id = NEW.warehouse_id AND m2.stock_item_id = NEW.stock_item_id
                          AND m2.id > NEW.last_movement_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: a balance can only change through a recorded movement';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_balances_bd BEFORE DELETE ON stock_balances FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INVENTORY_LEDGER: balances cannot be deleted';
            END
        SQL);
    }
};
