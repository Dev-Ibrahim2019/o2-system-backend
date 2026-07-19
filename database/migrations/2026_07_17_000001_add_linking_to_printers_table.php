<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->foreignId('linked_pos_register_id')
                  ->nullable()
                  ->after('type')
                  ->constrained('pos_registers')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropForeign(['linked_pos_register_id']);
            $table->dropColumn('linked_pos_register_id');
        });
    }
};
