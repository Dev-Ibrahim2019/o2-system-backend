<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) return;

        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 50)->change();
        });

        if (! Schema::hasColumn('payments', 'reference_number')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('reference_number', 255)
                    ->nullable()
                    ->after('amount');
            });
        }

        try {
            Schema::table('payments', function (Blueprint $table) {
                $table->unique(['invoice_id', 'reference_number'], 'uq_payments_invoice_ref');
            });
        } catch (\Exception $e) {
            logger()->warning('Unique constraint uq_payments_invoice_ref could not be created: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) return;

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('uq_payments_invoice_ref');
        });
    }
};
