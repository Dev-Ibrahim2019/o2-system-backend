<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * order_items has never carried its own timestamp — OrderTimelineController
 * fell back to the parent order's created_at for every "item added" event,
 * so an item added to an already-saved order (addItem()/store() called again
 * later) rendered as if it happened at the order's original opening time,
 * indistinguishable from the items that were actually there from the start.
 *
 * Backfilled from orders.created_at for existing rows — the closest true
 * value available; nothing better is recoverable after the fact. New rows
 * are stamped explicitly by OrderController::createOrderItem() and
 * CallCenterOrderCreationService::createItem() (OrderItem keeps
 * $timestamps = false, so this is never auto-managed by Eloquent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->after('order_id');
        });

        DB::statement(
            'UPDATE order_items oi
             JOIN orders o ON o.id = oi.order_id
             SET oi.created_at = o.created_at
             WHERE oi.created_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('created_at');
        });
    }
};
