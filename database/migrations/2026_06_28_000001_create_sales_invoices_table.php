<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();

            // ── Identification ──
            $table->string('number')->unique();
            $table->uuid('uuid')->unique();
            $table->string('reference_number')->nullable();

            // ── Type & Status ──
            $table->enum('type', ['tax_invoice', 'simple_invoice', 'credit_note', 'debit_note'])->default('tax_invoice');
            $table->enum('status', ['draft', 'awaiting_approval', 'awaiting_payment', 'paid', 'cancelled'])->default('draft');
            $table->enum('tax_treatment', ['inclusive', 'exclusive'])->default('exclusive');

            // ── Entity (Customer) ──
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_vat_number')->nullable();

            // ── Dates ──
            $table->dateTime('invoice_date');
            $table->date('due_date')->nullable();
            $table->date('supply_date')->nullable();

            // ── Financial ──
            $table->string('currency', 10)->default('ILS');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('discount_total', 15, 4)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->decimal('paid_amount', 15, 4)->default(0);
            $table->decimal('remaining_amount', 15, 4)->default(0);

            // ── Branch ──
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // ── Source Tracking ──
            $table->enum('source', ['manual', 'pos_sync', 'excel_import'])->default('manual');
            $table->string('pos_register_id')->nullable();
            $table->date('batch_date')->nullable();

            // ── ZATCA Compliance ──
            $table->string('zatca_uuid')->nullable();
            $table->text('zatca_hash')->nullable();
            $table->text('zatca_qr_data')->nullable();
            $table->dateTime('zatca_sent_at')->nullable();
            $table->enum('zatca_status', ['pending', 'submitted', 'accepted', 'rejected'])->nullable();

            // ── Notes ──
            $table->text('notes')->nullable();

            // ── Approval ──
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();

            // ── Accounting ──
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // ── Indexes ──
            $table->index(['status', 'branch_id']);
            $table->index(['customer_id', 'status']);
            $table->index('invoice_date');
            $table->index('due_date');
            $table->index('batch_date');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
