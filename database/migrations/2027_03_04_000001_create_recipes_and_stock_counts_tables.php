<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 4: الوصفات (بنسخ وتاريخ سريان)، وربط القسم بمخزنه، والجرد الفعلي.
 *
 * - الوصفة لمنتج في المنيو (items) أو لمنتج وسيط مخزني (stock_items، مثل الثومية).
 *   تعديل الوصفة يُنشئ نسخة جديدة من تاريخ سريانها، فيبقى الاستهلاك النظري للفترات السابقة
 *   محسوباً بالوصفة التي كانت سارية وقتها.
 * - warehouses.department_id: مبيعات القسم في فرع المخزن تُنسب لهذا المخزن (قسم الشاورما ← مخزن
 *   قسم الشاورما). الأقسام غير المربوطة تُنسب لمخزن البيع الافتراضي للفرع.
 * - الجرد: ورقة لكل مخزن؛ ترحيلها يسجّل حركة "تسوية جرد" للفرق عبر StockLedger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('branch_id')->constrained('departments')->nullOnDelete();
            // قسم واحد ← مخزن واحد في الفرع نفسه.
            $table->unique(['branch_id', 'department_id']);
        });

        Schema::table('inventory_settings', function (Blueprint $table) {
            // الفرق (فعلي − نظري) الذي يُعتبر مقلقاً في تحليلات المخزون.
            $table->decimal('variance_alert_percent', 5, 2)->default(3)->after('allow_negative_stock');
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            // المالك: منتج منيو أو منتج وسيط — واحد فقط (مفروض بقيد CHECK أدناه).
            $table->foreignId('item_id')->nullable()->constrained('items')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->cascadeOnDelete();
            $table->date('effective_from');
            // المنتج: عدد الحصص التي تنتجها الوصفة (عادة 1). الوسيط: الكمية الناتجة بوحدته الأساسية.
            $table->decimal('yield_quantity', 18, 6)->default(1);
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['item_id', 'effective_from']);
            $table->unique(['stock_item_id', 'effective_from']);
        });
        DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_single_owner CHECK ((item_id IS NULL) <> (stock_item_id IS NULL))');

        Schema::create('recipe_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            // ما أدخله المستخدم (مثلاً 120 غرام أو 0.12 كغ)، وما يُحسب به دائماً: الكمية بالوحدة الأساسية.
            $table->foreignId('unit_id')->constrained('stock_units')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('base_quantity', 18, 6);
            $table->timestamps();

            $table->unique(['recipe_id', 'stock_item_id']);
            $table->index('stock_item_id');
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->enum('status', ['draft', 'posted'])->default('draft');
            // اليوم المحاسبي للترحيل (يُملأ عند الترحيل؛ الجرد لا يُؤرَّخ بأثر رجعي).
            $table->date('count_date')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->decimal('variance_value', 20, 6)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'status', 'count_date']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            // الرصيد المسجّل عند إنشاء الورقة (للإرشاد)، ثم عند الترحيل (أساس الفرق).
            $table->decimal('expected_quantity', 18, 6)->default(0);
            // المعدود بالوحدة المُدخلة وبالأساسية؛ فارغ = لم يُعدّ (لا تسوية له).
            $table->foreignId('unit_id')->nullable()->constrained('stock_units')->restrictOnDelete();
            $table->decimal('entered_quantity', 18, 6)->nullable();
            $table->decimal('counted_quantity', 18, 6)->nullable();
            // تُملأ عند الترحيل.
            $table->decimal('variance_quantity', 18, 6)->nullable();
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->decimal('variance_value', 20, 6)->nullable();
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['stock_count_id', 'stock_item_id']);
            $table->index('stock_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('recipe_lines');
        Schema::dropIfExists('recipes');
        if (Schema::hasColumn('inventory_settings', 'variance_alert_percent')) {
            Schema::table('inventory_settings', fn (Blueprint $t) => $t->dropColumn('variance_alert_percent'));
        }

        // MySQL أسقط الفهرس التلقائي لمفتاح branch_id عند إضافة الفهرس الفريد (branch_id, department_id)
        // لأنه يغطيه؛ فيُضاف فهرس عادي لـ branch_id أولاً، وإلا يُرفض حذف الفهرس الفريد (يحتاجه المفتاح).
        $indexes = collect(DB::select('SHOW INDEX FROM warehouses'))->pluck('Key_name')->unique();
        Schema::table('warehouses', function (Blueprint $table) use ($indexes) {
            if (! $indexes->contains('warehouses_branch_id_index')) {
                $table->index('branch_id', 'warehouses_branch_id_index');
            }
            if ($indexes->contains('warehouses_branch_id_department_id_unique')) {
                $table->dropUnique('warehouses_branch_id_department_id_unique');
            }
        });
        if (Schema::hasColumn('warehouses', 'department_id')) {
            Schema::table('warehouses', fn (Blueprint $t) => $t->dropConstrainedForeignId('department_id'));
        }
    }
};
