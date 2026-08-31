<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which operational department a complaint is about.
 *
 * Analytical, not operational: it answers "where do complaints cluster" for
 * reporting. It is deliberately unrelated to assigned_to, which names the
 * person responsible for working the ticket — the two can disagree without
 * either being wrong, and nothing here touches assignment.
 *
 * An enum for the same reason `channel` is one: a closed set owned by the
 * code, safe to constrain now because the table is empty (0 rows verified
 * before writing this). No departments table by decision — this is a fixed
 * list, not managed data.
 *
 * Nullable with no default: classifying is optional and must not be implied.
 * A complaint nobody categorised should read as uncategorised, not as
 * whichever value happened to be first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            $table->enum('department', [
                'hospitality',
                'pos',
                'call_center',
                'kitchen',
                'delivery',
                'accounting',
                'management',
            ])->nullable()->after('channel');

            // Pairs with the created_at ordering the listing already uses,
            // matching the index added alongside `channel`.
            $table->index(['department', 'created_at'], 'cc_department_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            $table->dropIndex('cc_department_created_index');
            $table->dropColumn('department');
        });
    }
};
