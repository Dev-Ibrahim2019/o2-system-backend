<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->foreignId('merged_with_id')
                ->nullable()
                ->after('last_order_at')
                ->constrained('dining_tables')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropForeign(['merged_with_id']);
            $table->dropColumn('merged_with_id');
        });
    }
};
