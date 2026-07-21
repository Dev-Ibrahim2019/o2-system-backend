<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_addresses') && !Schema::hasColumn('customer_addresses', 'last_used_at')) {
            Schema::table('customer_addresses', function (Blueprint $table) {
                $table->timestamp('last_used_at')->nullable()->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_addresses') && Schema::hasColumn('customer_addresses', 'last_used_at')) {
            Schema::table('customer_addresses', function (Blueprint $table) {
                $table->dropColumn('last_used_at');
            });
        }
    }
};
