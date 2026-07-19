<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->timestamp('seated_at')->nullable()->after('status');
            $table->integer('customer_count')->default(0)->after('seated_at');
            $table->timestamp('last_order_at')->nullable()->after('customer_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropColumn('seated_at');
            $table->dropColumn('customer_count');
            $table->dropColumn('last_order_at');
        });
    }
};
