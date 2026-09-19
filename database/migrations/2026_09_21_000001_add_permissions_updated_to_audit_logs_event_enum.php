<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE audit_logs MODIFY COLUMN event ENUM(
            'created', 'updated', 'deleted', 'restored', 'posted', 'cancelled', 'reversed', 'permissions_updated'
        ) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE audit_logs MODIFY COLUMN event ENUM(
            'created', 'updated', 'deleted', 'restored', 'posted', 'cancelled', 'reversed'
        ) NOT NULL");
    }
};
