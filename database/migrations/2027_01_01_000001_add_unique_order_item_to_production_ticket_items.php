<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_ticket_items', function (Blueprint $table) {
            $table->unique('order_item_id', 'production_ticket_items_order_item_unique');
        });
    }

    public function down(): void
    {
        Schema::table('production_ticket_items', function (Blueprint $table) {
            $table->dropUnique('production_ticket_items_order_item_unique');
        });
    }
};
