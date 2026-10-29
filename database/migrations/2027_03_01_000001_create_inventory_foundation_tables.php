<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * وحدة المخازن — المرحلة 1 (الأساسيات).
 *
 * إضافة فقط: لا يُعدَّل أي جدول قائم هنا. الأصناف المخزنية (stock_items) جدول
 * مستقل يرتبط اختيارياً بصنف O2 المباع (items) — المواد الخام والمنتجات الوسيطة
 * لا تدخل جدول items إطلاقاً، فلا تظهر في القائمة ولا في شاشات البيع.
 *
 * كل الكميات في الوحدة تُخزَّن بالوحدة الأساسية للصنف: decimal(18,6) يكفي
 * للبيع بالوزن (0.077 كغ) ولتحويل الغرام إلى كيلو دون فقدان دقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // وحدات القياس العامة (غرام، كيلو، حبة...). معامل التحويل ليس هنا بل لكل صنف.
        Schema::create('stock_units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('symbol', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // تصنيفات المواد الخام — شجرة مستقلة عن أقسام البيع (القرار 12).
        Schema::create('stock_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('stock_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 30)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            // ربط بصنف O2 المباع — للمنتج النهائي والصنف الذي لا يخضع للمخزون فقط.
            // nullOnDelete: حذف صنف من شاشة الأصناف الحالية يجب ألا يتعطّل بسبب الوحدة.
            $table->foreignId('item_id')->nullable()->unique()->constrained('items')->nullOnDelete();
            $table->enum('type', ['raw', 'intermediate', 'finished', 'non_stock']);
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->foreignId('base_unit_id')->constrained('stock_units')->restrictOnDelete();
            // "يُخزَّن": يُخصم الصنف نفسه عند البيع بدل تفكيك وصفته (القرار 1).
            $table->boolean('is_stored')->default(true);
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('supplier_code', 64)->nullable();
            $table->decimal('safety_stock', 18, 6)->default(0);
            $table->boolean('tracks_expiry')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        // الوحدات البديلة للصنف: 1 من هذه الوحدة = factor من الوحدة الأساسية.
        Schema::create('stock_item_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('stock_units')->restrictOnDelete();
            $table->decimal('factor', 18, 6);
            $table->timestamps();

            $table->unique(['stock_item_id', 'unit_id']);
        });

        // علاقة متعددة: الصنف قد يتبع أكثر من تصنيف.
        Schema::create('stock_category_item', function (Blueprint $table) {
            $table->foreignId('stock_category_id')->constrained('stock_categories')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();
            $table->primary(['stock_category_id', 'stock_item_id']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            // null فقط للمخزن الافتراضي "قيد النقل" (القرار 5).
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->enum('type', ['standard', 'transit'])->default('standard');
            // مخزن البيع الافتراضي للفرع (القرار 10) — مخزن واحد لكل فرع،
            // مفروض في قاعدة البيانات عبر العمود المولَّد الفريد أدناه.
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // = branch_id عندما يكون المخزن مخزن البيع الافتراضي، وإلا NULL.
        // القيم NULL لا تتعارض في الفهرس الفريد، فيبقى قيد "واحد لكل فرع" فقط.
        DB::statement('ALTER TABLE warehouses ADD COLUMN default_sales_branch_key BIGINT UNSIGNED
            GENERATED ALWAYS AS (IF(is_default_sales, branch_id, NULL)) STORED');
        DB::statement('ALTER TABLE warehouses ADD UNIQUE INDEX warehouses_default_sales_branch_unique (default_sales_branch_key)');

        // المخازن المسموح للمستخدم بالعمل عليها. لا ربط = لا وصول، إلا بصلاحية
        // inventory.warehouses.all أو دور super-admin.
        Schema::create('warehouse_user', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['warehouse_id', 'user_id']);
        });

        $now = now();
        DB::table('stock_units')->insertOrIgnore(array_map(
            fn ($u) => ['name' => $u[0], 'symbol' => $u[1], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            [['غرام', 'g'], ['كيلوغرام', 'kg'], ['مليلتر', 'ml'], ['لتر', 'L'], ['حبة', 'pc'], ['علبة', 'box'], ['كرتونة', 'ctn']],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_user');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('stock_category_item');
        Schema::dropIfExists('stock_item_units');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('stock_categories');
        Schema::dropIfExists('stock_units');
    }
};
