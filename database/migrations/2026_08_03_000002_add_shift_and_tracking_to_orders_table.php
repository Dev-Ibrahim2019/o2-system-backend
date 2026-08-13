<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        if (!Schema::hasColumn('orders', 'shift_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete()->after('branch_id');
            });
        }

        if (!Schema::hasColumn('orders', 'opened_by')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete()->after('cashier_id');
            });
        }

        if (!Schema::hasColumn('orders', 'closed_by')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete()->after('opened_by');
            });
        }

        if (!Schema::hasColumn('orders', 'printed_by')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete()->after('closed_by');
            });
        }

        if (!Schema::hasColumn('orders', 'printed_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('printed_at')->nullable()->after('printed_by');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'shift_id')) {
                $table->dropForeign(['shift_id']);
                $table->dropColumn('shift_id');
            }

            if (Schema::hasColumn('orders', 'opened_by')) {
                $table->dropForeign(['opened_by']);
                $table->dropColumn('opened_by');
            }

            if (Schema::hasColumn('orders', 'closed_by')) {
                $table->dropForeign(['closed_by']);
                $table->dropColumn('closed_by');
            }

            if (Schema::hasColumn('orders', 'printed_by')) {
                $table->dropForeign(['printed_by']);
                $table->dropColumn('printed_by');
            }

            if (Schema::hasColumn('orders', 'printed_at')) {
                $table->dropColumn('printed_at');
            }
        });
    }
};
