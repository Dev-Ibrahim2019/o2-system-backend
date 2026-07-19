<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();

            // ── Identification ──
            $table->string('quote_number')->unique();
            $table->string('share_token', 64)->nullable()->unique();

            // ── Status ──
            $table->enum('status', ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'])->default('draft');

            // ── Customer ──
            $table->foreignId('client_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('client_name')->nullable();
            $table->string('client_phone')->nullable();
            $table->string('client_email')->nullable();

            // ── Issuer ──
            $table->foreignId('issuer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // ── Dates ──
            $table->date('issue_date');
            $table->date('expiry_date')->nullable();

            // ── Financial ──
            $table->string('currency', 10)->default('ILS');
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('tax_total', 15, 4)->default(0);
            $table->decimal('discount_total', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);

            // ── Content ──
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            // ── Conversion ──
            $table->foreignId('converted_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
