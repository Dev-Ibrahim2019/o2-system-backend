<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-item feedback, the item-level counterpart of order_feedback (which is
 * one row per order, food/service/delivery only). One row per order_item —
 * re-rating an item updates that row, it never accumulates history, the same
 * choice order_feedback made with its unique order_id.
 *
 * order_id is denormalised alongside order_item_id so "all item feedback for
 * this order" is one indexed lookup, without a join back through order_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_feedback');
    }
};
