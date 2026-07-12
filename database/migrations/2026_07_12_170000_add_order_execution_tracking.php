<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'assembly_started_at')) $table->timestamp('assembly_started_at')->nullable()->after('paid_at');
            if (! Schema::hasColumn('orders', 'assembler_id')) $table->foreignId('assembler_id')->nullable()->after('assembly_started_at')->constrained('employees')->nullOnDelete();
            if (! Schema::hasColumn('orders', 'assembled_by')) $table->foreignId('assembled_by')->nullable()->after('assembled_at')->constrained('employees')->nullOnDelete();
            if (! Schema::hasColumn('orders', 'assembly_duration_seconds')) $table->unsignedInteger('assembly_duration_seconds')->nullable()->after('assembled_by');
            if (! Schema::hasColumn('orders', 'delivery_assigned_by')) $table->foreignId('delivery_assigned_by')->nullable()->after('driver_id')->constrained('users')->nullOnDelete();
        });

        if (! Schema::hasTable('order_execution_events')) {
            Schema::create('order_execution_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->string('event_type', 50)->index();
                $table->string('from_status', 50)->nullable();
                $table->string('to_status', 50)->nullable();
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->useCurrent()->index();
                $table->timestamps();
                $table->index(['order_id', 'event_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_execution_events');
        Schema::table('orders', function (Blueprint $table) {
            foreach (['delivery_assigned_by','assembled_by','assembler_id'] as $column) if (Schema::hasColumn('orders', $column)) $table->dropConstrainedForeignId($column);
            foreach (['assembly_duration_seconds','assembly_started_at'] as $column) if (Schema::hasColumn('orders', $column)) $table->dropColumn($column);
        });
    }
};
