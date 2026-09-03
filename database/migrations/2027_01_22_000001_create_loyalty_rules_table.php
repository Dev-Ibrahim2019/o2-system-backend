<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty pricing lives in rows, not code, so a manager can add a "double
 * points on desserts this week" rule without a deploy. There is exactly one
 * permanent kind of row here — the base rate — and every other row is a
 * multiplier layered on top of it; see LoyaltyRule::BASE_RULE_NAME for how
 * that single row is found and protected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            $table->enum('scope_type', ['global', 'customer', 'group', 'category', 'product']);
            // Polymorphic in meaning, not in Eloquent's morph sense: which
            // table scope_id points into is implied by scope_type, and
            // 'global' carries no id at all. A real morph column would let a
            // 'global' row wrongly point somewhere.
            $table->unsignedBigInteger('scope_id')->nullable();

            // The base rate. Required (application-level, see the model) only
            // on the one permanent global row; every other rule leaves these
            // null and contributes through multiplier instead.
            $table->decimal('points_per_amount', 10, 4)->nullable();
            $table->decimal('per_amount', 10, 4)->nullable();

            $table->decimal('multiplier', 8, 4)->default(1.0);

            // Presence, not value, is what marks a rule as invoice-level
            // rather than item-level — see the listener's two-pass split.
            $table->decimal('min_order_value', 12, 3)->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->integer('priority')->default(0);

            $table->boolean('is_campaign')->default(false);
            $table->decimal('campaign_target', 12, 3)->nullable();
            $table->enum('campaign_target_metric', ['spend', 'points', 'order_count'])->nullable();

            $table->decimal('group_cascade_percent', 5, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The listener's matching query for every scope but 'global'
            // filters on (scope_type, scope_id, is_active) together.
            $table->index(['scope_type', 'scope_id', 'is_active'], 'lr_scope_active_index');
            $table->index(['is_active', 'starts_at', 'ends_at'], 'lr_active_window_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rules');
    }
};
