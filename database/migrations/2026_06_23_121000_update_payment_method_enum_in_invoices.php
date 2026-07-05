<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) return;

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_method', 50)
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices')) return;

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_method', 50)->nullable()->change();
        });
    }
};
