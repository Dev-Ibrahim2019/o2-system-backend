<?php
// ✅ هاد الملف لازم يكون قبل entries في الترتيب
// اسم الملف: 2026_05_06_131100_create_cost_centers_table.php
// (تاريخ أصغر من entries: 131205)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code')->unique()->nullable(); // ✅ إضافة: كود مركز التكلفة

            $table->enum('type', [
                'operational',    // تشغيلي
                'administrative', // إداري
                'service',        // خدمي
                'production',     // إنتاجي
            ])->nullable();

            // شجرة مراكز التكلفة
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('cost_centers')
                ->nullOnDelete();

            // ✅ إضافة: ربط مركز التكلفة بفرع (اختياري)
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            // ✅ إضافة: is_active (كانت ناقصة في الأصل)
            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable(); // ✅ إضافة

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
