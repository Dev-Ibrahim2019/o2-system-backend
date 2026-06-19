<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Remove account_id - we use subledger only
            if (Schema::hasColumn('customers', 'account_id')) {
                $table->dropForeign(['account_id']);
                $table->dropColumn('account_id');
            }

            // Add new fields matching supplier pattern
            if (!Schema::hasColumn('customers', 'mobile')) {
                $table->string('mobile')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('customers', 'city')) {
                $table->string('city', 100)->nullable()->after('address');
            }
            if (!Schema::hasColumn('customers', 'country')) {
                $table->string('country', 100)->nullable()->after('city');
            }
            if (!Schema::hasColumn('customers', 'category')) {
                $table->string('category', 50)->nullable()->after('country');
            }
            if (!Schema::hasColumn('customers', 'currency')) {
                $table->string('currency', 3)->default('ILS')->after('category');
            }
            if (!Schema::hasColumn('customers', 'payment_terms')) {
                $table->string('payment_terms', 20)->default('net30')->after('credit_limit');
            }
            if (!Schema::hasColumn('customers', 'credit_days')) {
                $table->integer('credit_days')->default(30)->after('payment_terms');
            }
            if (!Schema::hasColumn('customers', 'opening_balance')) {
                $table->decimal('opening_balance', 15, 3)->default(0)->after('credit_days');
            }
            if (!Schema::hasColumn('customers', 'is_opening_balance_posted')) {
                $table->boolean('is_opening_balance_posted')->default(false)->after('opening_balance');
            }
            if (!Schema::hasColumn('customers', 'risk_level')) {
                $table->string('risk_level', 20)->default('low')->after('status');
            }
            if (!Schema::hasColumn('customers', 'notes')) {
                $table->text('notes')->nullable()->after('is_opening_balance_posted');
            }
            if (!Schema::hasColumn('customers', 'gps_link')) {
                $table->string('gps_link', 500)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('customers', 'website')) {
                $table->string('website', 255)->nullable()->after('email');
            }
            if (!Schema::hasColumn('customers', 'salesperson_id')) {
                $table->foreignId('salesperson_id')->nullable()->constrained('employees')->nullOnDelete()->after('branch_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'mobile',
                'city',
                'country',
                'category',
                'currency',
                'payment_terms',
                'credit_days',
                'opening_balance',
                'is_opening_balance_posted',
                'risk_level',
                'notes',
                'gps_link',
                'website',
                'salesperson_id',
            ]);
            // Restore account_id
            if (!Schema::hasColumn('customers', 'account_id')) {
                $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            }
        });
    }
};
