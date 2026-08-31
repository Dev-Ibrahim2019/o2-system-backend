<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity-conflict tickets.
 *
 * Today, when an incoming order carries a phone that already belongs to a
 * customer but a DIFFERENT name, CallCenterOrderCreationService keeps the
 * stored name and silently drops the incoming one (see that file, the
 * `$resolved['customer'] ?:` branch). Correct — but the discrepancy leaves no
 * trace. This table is that trace: one row per unreviewed discrepancy, which
 * a CRM operator resolves explicitly.
 *
 * Deliberately NOT on the order's critical path — rows are written after the
 * order transaction commits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_identity_conflicts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // Channels mirror the values already used by customers.source
            // (see CrmController::CUSTOMER_SOURCE_VALUES) so the two stay
            // readable together; 'walk_in' is excluded because a walk-in has
            // no captured identity to conflict with.
            $table->enum('source_channel', ['call_center', 'pos_instant', 'pos_family', 'website']);

            // nullSafe: a conflict can outlive the order it came from, and
            // some channels (identity edit, import) have no order at all.
            $table->foreignId('source_order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->string('incoming_name');
            $table->string('incoming_phone_normalized', 20);

            $table->enum('status', ['open', 'resolved', 'dismissed'])->default('open');
            $table->enum('resolution', [
                'kept_original',
                'renamed_customer',
                'created_new_customer',
                'marked_shared_number',
            ])->nullable();
            $table->text('resolution_note')->nullable();

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // The queue view is "open tickets, newest first", optionally by
            // channel — this covers both without a second index.
            // Explicit short names: the generated ones exceed MySQL's
            // 64-character identifier limit for this table name.
            $table->index(['status', 'source_channel', 'created_at'], 'cic_queue_index');
            $table->index('customer_id', 'cic_customer_index');

            // Suppression lookup: "has this number already been resolved as
            // shared?" runs on every conflict candidate, so it must not be a
            // table scan.
            $table->index(['incoming_phone_normalized', 'resolution'], 'cic_shared_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_identity_conflicts');
    }
};
