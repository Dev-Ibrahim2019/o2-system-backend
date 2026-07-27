<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'customer_mobile')) {
                $table->string('customer_mobile', 30)->nullable()->after('customer_phone');
            }
            if (!Schema::hasColumn('orders', 'needs_attention')) {
                $table->boolean('needs_attention')->default(false)->after('note');
            }
            if (!Schema::hasColumn('orders', 'customer_service_flag')) {
                $table->boolean('customer_service_flag')->default(false)->after('needs_attention');
            }
            if (!Schema::hasColumn('orders', 'customer_notes')) {
                $table->text('customer_notes')->nullable()->after('customer_service_flag');
            }
            if (!Schema::hasColumn('orders', 'delivery_notes')) {
                $table->text('delivery_notes')->nullable()->after('customer_notes');
            }
            if (!Schema::hasColumn('orders', 'call_notes')) {
                $table->text('call_notes')->nullable()->after('delivery_notes');
            }
            if (!Schema::hasColumn('orders', 'call_center_agent_id')) {
                $table->foreignId('call_center_agent_id')->nullable()->constrained('users')->nullOnDelete()->after('cashier_id');
            }
            if (!Schema::hasColumn('orders', 'source')) {
                $table->string('source', 50)->default('call_center')->after('order_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = ['customer_mobile', 'needs_attention', 'customer_service_flag', 'customer_notes', 'delivery_notes', 'call_notes', 'call_center_agent_id', 'source'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
