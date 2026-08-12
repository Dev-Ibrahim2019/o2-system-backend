<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete()->after('branch_id');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete()->after('cashier_id');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete()->after('opened_by');
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete()->after('closed_by');
            $table->timestamp('printed_at')->nullable()->after('printed_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropForeign(['opened_by']);
            $table->dropForeign(['closed_by']);
            $table->dropForeign(['printed_by']);
            $table->dropColumn(['shift_id', 'opened_by', 'closed_by', 'printed_by', 'printed_at']);
        });
    }
};
