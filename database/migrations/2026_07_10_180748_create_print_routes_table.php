<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('print_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');

            // المصدر
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');

            // الهدف: الآن يدعم تحديد قسم كامل أو صنف بعينه 🎯
            $table->foreignId('category_id')->nullable()->constrained('departments')->onDelete('cascade'); // توجيه قسم كامل
            $table->foreignId('item_id')->nullable()->constrained('items')->onDelete('cascade'); // 👈 توجيه صنف محدد

            // المستهدف
            $table->foreignId('printer_id')->constrained('printers')->onDelete('cascade');

            $table->string('action_type')->default('KOT'); // KOT أو BILL
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('print_routes');
    }
};
