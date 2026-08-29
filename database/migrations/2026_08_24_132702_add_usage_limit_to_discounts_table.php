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
        Schema::table('discounts', function (Blueprint $table) {
            // null = بلا حد أقصى للاستخدام. العداد نفسه بيتحسب ديناميكياً من
            // discount_usage_logs بدل عمود counter منفصل (تفادياً لتزامن غلط).
            $table->unsignedInteger('usage_limit')->nullable()->after('max_discount_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn('usage_limit');
        });
    }
};
