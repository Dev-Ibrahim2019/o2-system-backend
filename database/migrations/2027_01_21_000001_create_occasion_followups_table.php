<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A yearly diary line against one occasion.
 *
 * Deliberately thinner than complaint_followups: no action, no status pair, no
 * type, no metadata. A complaint followup records a lifecycle event, so it has
 * to say what changed; an occasion followup records what was done for someone
 * on the day ("اتصلنا وهنّأناه، طلب كيك شوكولاتة"), and next year the reader
 * wants the previous lines in order, nothing more.
 *
 * No cycle_year column either. Tying a line to a specific occurrence would
 * make it un-writable outside its window and would need a rule for a note
 * added a week late; ordering by created_at answers "what happened last time"
 * without inventing that rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occasion_followups', function (Blueprint $table) {
            $table->id();

            // Cascade, unlike complaint_followups: an occasion is soft-deleted
            // in normal use, so this fires only on a real hard delete, where
            // orphan diary rows would have nothing to belong to.
            $table->foreignId('occasion_id')
                ->constrained('customer_occasions')
                ->cascadeOnDelete();

            $table->text('notes');

            // Nullable and nullOnDelete, matching complaint_followups.user_id:
            // deleting a staff account must not erase the history they wrote.
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // The one read this table has: newest-first for a single occasion.
            $table->index(['occasion_id', 'created_at'], 'of_occasion_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occasion_followups');
    }
};
