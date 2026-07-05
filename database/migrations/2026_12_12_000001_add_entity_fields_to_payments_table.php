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
            if (! Schema::hasColumn('payments', 'entity_type')) {
                $table->string('entity_type')->nullable()->after('method');
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            }
            if (! Schema::hasColumn('payments', 'subledger_type')) {
                $table->string('subledger_type')->nullable()->after('entity_id');
                $table->unsignedBigInteger('subledger_id')->nullable()->after('subledger_type');
            }
            if (! Schema::hasColumn('payments', 'payment_method_id')) {
                $table->unsignedBigInteger('payment_method_id')->nullable()->after('method');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) return;

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'entity_type',
                'entity_id',
                'subledger_type',
                'subledger_id',
                'payment_method_id',
            ]);
        });
    }
};
