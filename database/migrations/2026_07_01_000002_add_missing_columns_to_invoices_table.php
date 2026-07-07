<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fix status enum to include all needed values
        if (Schema::hasTable('invoices')) {
            DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('draft', 'awaiting_approval', 'awaiting_payment', 'partial', 'paid', 'cancelled') DEFAULT 'draft'");
        }

        // Fix payment_method enum to match frontend values
        if (Schema::hasTable('invoices')) {
            DB::statement("ALTER TABLE invoices MODIFY COLUMN payment_method ENUM('cash', 'credit_card', 'bank_transfer', 'app', 'account', 'mixed', 'card', 'bank', 'wallet') DEFAULT NULL");
        }

        // Fix payments method enum to match frontend values
        if (Schema::hasTable('payments')) {
            DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash', 'credit_card', 'bank_transfer', 'app', 'account', 'mixed', 'card', 'bank', 'wallet') DEFAULT 'cash'");
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function ($table) {
                if (! Schema::hasColumn('invoices', 'type')) {
                    $table->string('type')->nullable()->after('number');
                }
                if (! Schema::hasColumn('invoices', 'customer_name')) {
                    $table->string('customer_name')->nullable()->after('customer_id');
                }
                if (! Schema::hasColumn('invoices', 'tax_total')) {
                    $table->decimal('tax_total', 15, 4)->default(0)->after('discount');
                }
                if (! Schema::hasColumn('invoices', 'currency')) {
                    $table->string('currency', 10)->default('ILS')->after('status');
                }
                if (! Schema::hasColumn('invoices', 'due_date')) {
                    $table->date('due_date')->nullable()->after('invoice_date');
                }
                if (! Schema::hasColumn('invoices', 'delivery_date')) {
                    $table->date('delivery_date')->nullable()->after('due_date');
                }
                if (! Schema::hasColumn('invoices', 'expected_payment_date')) {
                    $table->date('expected_payment_date')->nullable()->after('delivery_date');
                }
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function ($table) {
                if (! Schema::hasColumn('invoice_items', 'description')) {
                    $table->text('description')->nullable()->after('item_name');
                }
                if (! Schema::hasColumn('invoice_items', 'unit_price')) {
                    $table->decimal('unit_price', 15, 4)->default(0)->after('description');
                }
                if (! Schema::hasColumn('invoice_items', 'discount')) {
                    $table->decimal('discount', 15, 4)->default(0)->after('unit_price');
                }
                if (! Schema::hasColumn('invoice_items', 'tax_rate')) {
                    $table->decimal('tax_rate', 5, 2)->default(0)->after('discount');
                }
                if (! Schema::hasColumn('invoice_items', 'tax_amount')) {
                    $table->decimal('tax_amount', 15, 4)->default(0)->after('tax_rate');
                }
                if (! Schema::hasColumn('invoice_items', 'total_before_tax')) {
                    $table->decimal('total_before_tax', 15, 4)->default(0)->after('tax_amount');
                }
            });
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function ($table) {
                if (! Schema::hasColumn('payments', 'reference_number')) {
                    $table->string('reference_number')->nullable()->after('amount');
                }
                if (! Schema::hasColumn('payments', 'payment_method_id')) {
                    $table->foreignId('payment_method_id')->nullable()->after('method')->constrained()->nullOnDelete();
                }
                if (! Schema::hasColumn('payments', 'entity_type')) {
                    $table->string('entity_type')->nullable()->after('user_id');
                    $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
                    $table->string('subledger_type')->nullable()->after('entity_id');
                    $table->unsignedBigInteger('subledger_id')->nullable()->after('subledger_type');
                }
            });
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE invoices MODIFY COLUMN status ENUM('draft', 'paid', 'partial', 'cancelled') DEFAULT 'draft'");
        DB::statement("ALTER TABLE invoices MODIFY COLUMN payment_method ENUM('cash', 'card', 'bank', 'wallet', 'account', 'mixed') DEFAULT NULL");
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('cash', 'card', 'bank', 'wallet', 'account', 'mixed') DEFAULT 'cash'");

        Schema::table('invoices', function ($table) {
            $table->dropColumn([
                'type', 'customer_name', 'tax_total', 'currency',
                'due_date', 'delivery_date', 'expected_payment_date',
            ]);
        });

        Schema::table('invoice_items', function ($table) {
            $table->dropColumn(['description', 'unit_price', 'discount', 'tax_rate', 'tax_amount', 'total_before_tax']);
        });

        Schema::table('payments', function ($table) {
            $table->dropColumn(['reference_number', 'payment_method_id', 'entity_type', 'entity_id', 'subledger_type', 'subledger_id']);
        });
    }
};