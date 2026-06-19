<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'department_id')) {
                $table->foreignId('department_id')
                    ->nullable()
                    ->after('item_id')
                    ->constrained()
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('order_items', 'item_name_ar')) {
                $table->string('item_name_ar')->nullable()->after('item_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'department_id')) {
                $table->dropForeign(['department_id']);
                $table->dropColumn('department_id');
            }
            if (Schema::hasColumn('order_items', 'item_name_ar')) {
                $table->dropColumn('item_name_ar');
            }
        });
    }
};
