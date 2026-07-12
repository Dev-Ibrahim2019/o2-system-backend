<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_complaints', 'title')) {
                $table->string('title', 255)->after('customer_id');
            }
            if (!Schema::hasColumn('customer_complaints', 'invoice_id')) {
                $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete()->after('order_id');
            }
            if (!Schema::hasColumn('customer_complaints', 'type')) {
                $table->string('type', 50)->default('other')->after('description');
            }
            if (!Schema::hasColumn('customer_complaints', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('resolved_at');
            }
            if (!Schema::hasColumn('customer_complaints', 'resolution_notes')) {
                $table->text('resolution_notes')->nullable()->after('closed_at');
            }
            if (!Schema::hasColumn('customer_complaints', 'resolution_result')) {
                $table->string('resolution_result', 50)->nullable()->after('resolution_notes');
            }
            if (!Schema::hasColumn('customer_complaints', 'severity')) {
                $table->string('severity', 20)->default('info')->after('resolution_result');
            }
            if (!Schema::hasColumn('customer_complaints', 'is_sensitive')) {
                $table->boolean('is_sensitive')->default(false)->after('severity');
            }
            if (!Schema::hasColumn('customer_complaints', 'show_alert')) {
                $table->boolean('show_alert')->default(true)->after('is_sensitive');
            }
            if (!Schema::hasColumn('customer_complaints', 'branch_id')) {
                $table->string('branch_id')->nullable()->after('show_alert');
            }
            if (!Schema::hasColumn('customer_complaints', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
            if (!Schema::hasColumn('customer_complaints', 'priority') && Schema::hasColumn('customer_complaints', 'status')) {
                $table->string('priority', 20)->default('normal')->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            $columns = ['title', 'invoice_id', 'type', 'closed_at', 'resolution_notes', 'resolution_result', 'severity', 'is_sensitive', 'show_alert', 'branch_id'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('customer_complaints', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('customer_complaints', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
