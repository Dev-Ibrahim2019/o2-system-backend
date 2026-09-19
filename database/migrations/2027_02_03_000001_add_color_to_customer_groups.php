<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small, fixed palette pick (see CustomerGroup::COLORS) — purely visual,
 * so the groups list/profile can tell groups apart at a glance the same way
 * the approved redesign does. Not a free-text hex field: a fixed set keeps
 * every group readable against the CRM's own light/soft-tone tokens instead
 * of a manager picking a colour that clashes with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->string('color', 20)->nullable()->after('group_type');
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
