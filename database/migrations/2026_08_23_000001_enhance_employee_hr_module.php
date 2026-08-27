<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('salary_type', 20)->default('monthly')->after('salary');
            $table->decimal('hourly_rate', 10, 2)->nullable()->after('salary_type');
            $table->decimal('daily_rate', 10, 2)->nullable()->after('hourly_rate');
            $table->decimal('standard_daily_hours', 5, 2)->default(8)->after('daily_rate');
        });

        Schema::create('employee_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 Sunday .. 6 Saturday
            $table->boolean('is_working_day')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'day_of_week']);
            $table->index(['branch_id', 'day_of_week']);
        });

        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('check_in')->nullable();
            $table->dateTime('check_out')->nullable();
            $table->string('status', 20)->default('PRESENT');
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });

        Schema::create('employee_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->foreignId('cash_account_id')->constrained('accounts');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('posted');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('reversal_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'date']);
        });

        Schema::create('employee_payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedTinyInteger('period_month');
            $table->unsignedSmallInteger('period_year');
            $table->string('salary_type', 20)->default('monthly');
            $table->decimal('worked_hours', 10, 2)->default(0);
            $table->unsignedSmallInteger('worked_days')->default(0);
            $table->decimal('base_salary', 12, 2);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('advance_deduction', 12, 2)->default(0);
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('payable_amount', 12, 2);
            $table->decimal('net_amount', 12, 2);
            $table->foreignId('cash_account_id')->constrained('accounts');
            $table->date('payment_date');
            $table->string('status', 20)->default('paid');
            $table->text('notes')->nullable();
            $table->foreignId('accrual_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'period_year', 'period_month'], 'employee_payroll_period_unique');
            $table->index(['period_year', 'period_month', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payrolls');
        Schema::dropIfExists('employee_withdrawals');
        Schema::dropIfExists('employee_attendances');
        Schema::dropIfExists('employee_work_schedules');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['salary_type', 'hourly_rate', 'daily_rate', 'standard_daily_hours']);
        });
    }
};
