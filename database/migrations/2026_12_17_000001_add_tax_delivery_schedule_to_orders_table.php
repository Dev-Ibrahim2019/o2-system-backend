<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'tax_rate')) {
                $table->decimal('tax_rate', 5, 2)->nullable()->default(0)->after('discount_amount');
            }
            if (! Schema::hasColumn('orders', 'tax_amount')) {
                $table->decimal('tax_amount', 12, 3)->nullable()->default(0)->after('tax_rate');
            }
            if (! Schema::hasColumn('orders', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('tax_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = array_filter(
                ['tax_rate', 'tax_amount', 'scheduled_at'],
                fn (string $column) => Schema::hasColumn('orders', $column)
            );
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
