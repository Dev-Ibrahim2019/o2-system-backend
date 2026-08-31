<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional membership: most customers are individuals and belong to no group,
 * so NULL is the normal state, not a gap to be filled.
 *
 * nullOnDelete, not cascade: dissolving a group must not delete its members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('group_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('customer_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropColumn('group_id');
        });
    }
};
