<?php
// database/migrations/2026_05_02_000002_fix_branch_item_and_items_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add is_active to items if missing ──────────────────────────
        if (!Schema::hasColumn('items', 'is_active')) {
            Schema::table('items', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('unit');
            });
        }

        // ── 2. Fix branch_item composite PK ──────────────────────────────
        // MySQL blocks DROP PRIMARY KEY when FK constraints reference those columns.
        // Strategy: detect & drop FKs → drop bad PK → add correct PK → re-add FKs.

        if (Schema::hasTable('branch_item')) {

            // Detect actual FK constraint names from information_schema
            $fks = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'branch_item'
                  AND REFERENCED_TABLE_NAME IS NOT NULL
            ");

            $fkNames = collect($fks)->pluck('CONSTRAINT_NAME')->toArray();

            Schema::table('branch_item', function (Blueprint $table) use ($fkNames) {
                // Drop every FK found on this table
                foreach ($fkNames as $fk) {
                    $table->dropForeign($fk);
                }

                // Now MySQL allows dropping the primary key
                $table->dropPrimary();

                // Re-add the correct composite PK (array syntax is required!)
                $table->primary(['branch_id', 'item_id']);

                // Re-add foreign keys
                $table->foreign('branch_id')
                      ->references('id')->on('branches')
                      ->cascadeOnDelete();

                $table->foreign('item_id')
                      ->references('id')->on('items')
                      ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Nothing to reverse safely
    }
};
