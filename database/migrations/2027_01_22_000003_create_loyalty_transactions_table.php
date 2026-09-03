<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The permanent ledger. A row, once written, is never updated — a correction
 * is always a new row (a 'manual_adjustment' or 'campaign_reversal'), the
 * same append-only discipline occasion_followups and customer_complaints'
 * followups already use for history that must stay honest. A customer's
 * balance is this table's signed sum, not a column anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();

            // Polymorphic in meaning only, matching loyalty_rules.scope_id —
            // a group cascade line and a customer's own line share this table,
            // and 'group' owner_id points at customer_groups while 'customer'
            // points at customers.
            $table->enum('owner_type', ['customer', 'group']);
            $table->unsignedBigInteger('owner_id');

            $table->enum('type', ['earn', 'redeem', 'referral_bonus', 'manual_adjustment', 'campaign_reversal']);
            $table->decimal('points', 12, 3);
            $table->enum('status', ['pending', 'confirmed', 'reversed'])->default('confirmed');

            $table->foreignId('source_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('loyalty_rules')->nullOnDelete();

            // Required in practice for manual_adjustment (an unexplained
            // balance change is worse than a blocked one), enforced in the
            // service layer rather than the schema — the same choice
            // customer_complaints.resolution_notes made for a conditional
            // requirement that a plain NOT NULL cannot express.
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // The one read this table has today: an owner's running balance
            // and history, newest first.
            $table->index(['owner_type', 'owner_id', 'created_at'], 'lt_owner_created_index');
            // A listener's idempotency check and "what did this order earn"
            // both key off the order.
            $table->index('source_order_id', 'lt_source_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
