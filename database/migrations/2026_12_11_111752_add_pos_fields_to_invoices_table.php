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
            if (! Schema::hasColumn('invoices', 'pos_register_id')) {
                $table->foreignId('pos_register_id')
                    ->nullable()
                    ->constrained('pos_registers')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'pos_code')) {
                $table->string('pos_code')->nullable()->after('pos_register_id');
                $table->string('pos_name')->nullable()->after('pos_code');
            }
            if (! Schema::hasColumn('invoices', 'opened_by')) {
                $table->foreignId('opened_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->dateTime('opened_at')->nullable()->after('opened_by');
            }
            if (! Schema::hasColumn('invoices', 'closed_by')) {
                $table->foreignId('closed_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->dateTime('closed_at')->nullable()->after('closed_by');
            }
            if (! Schema::hasColumn('invoices', 'account_number')) {
                $table->string('account_number')->nullable()->after('closed_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices')) return;

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'pos_register_id')) {
                $table->dropConstrainedForeignId('pos_register_id');
            }
            if (Schema::hasColumn('invoices', 'opened_by')) {
                $table->dropConstrainedForeignId('opened_by');
            }
            if (Schema::hasColumn('invoices', 'closed_by')) {
                $table->dropConstrainedForeignId('closed_by');
            }
            $table->dropColumn([
                'pos_code',
                'pos_name',
                'opened_at',
                'closed_at',
                'account_number',
            ]);
        });
    }
};
