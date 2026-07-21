<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('call_tickets', function (Blueprint $table) {
            $table->id(); $table->string('external_call_id')->nullable()->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('linked_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('direction',16)->default('inbound'); $table->string('status',24)->default('ringing');
            $table->string('incoming_phone',40); $table->string('normalized_phone',24);
            $table->string('source')->nullable(); $table->string('disposition')->nullable(); $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable(); $table->timestamp('answered_at')->nullable(); $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable(); $table->json('metadata')->nullable(); $table->timestamps();
            $table->index('normalized_phone'); $table->index('customer_id'); $table->index('agent_id');
            $table->index(['branch_id','status']); $table->index('started_at');
        });
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope',80); $table->uuid('key'); $table->char('request_hash',64);
            $table->string('resource_type')->nullable(); $table->unsignedBigInteger('resource_id')->nullable();
            $table->json('response')->nullable(); $table->unsignedSmallInteger('response_status')->default(200); $table->timestamps();
            $table->unique(['scope','key']);
        });
        Schema::create('order_cancellation_requests', function (Blueprint $table) {
            $table->id(); $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_trip_stop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source',24); $table->string('reason_code',40); $table->text('reason_text')->nullable();
            $table->boolean('customer_confirmed')->default(false); $table->string('status',24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable(); $table->text('resolution_note')->nullable(); $table->timestamps();
            $table->index(['order_id','status']);
        });
        Schema::table('delivery_trips', function (Blueprint $table) {
            $table->foreignId('assigned_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('max_stops')->default(3)->after('status');
            $table->timestamp('cancelled_at')->nullable(); $table->text('cancellation_reason')->nullable();
        });
        Schema::table('delivery_trip_stops', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable(); $table->timestamp('cancelled_at')->nullable(); $table->text('cancellation_reason')->nullable();
        });
        Schema::table('items', fn(Blueprint $table)=>$table->boolean('is_weight_based')->default(false));
        Schema::table('order_items', fn(Blueprint $table)=>$table->unsignedInteger('weight_grams')->nullable());
        Schema::table('orders', function(Blueprint $table){
            $table->decimal('manual_adjustment',12,3)->default(0); $table->text('adjustment_reason')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('adjusted_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('orders',fn(Blueprint $table)=>$table->drop(['manual_adjustment','adjustment_reason','adjusted_by','adjusted_at']));
        Schema::table('order_items',fn(Blueprint $table)=>$table->dropColumn('weight_grams'));
        Schema::table('items',fn(Blueprint $table)=>$table->dropColumn('is_weight_based'));
        Schema::table('delivery_trip_stops',fn(Blueprint $table)=>$table->drop(['failed_at','cancelled_at','cancellation_reason']));
        Schema::table('delivery_trips',fn(Blueprint $table)=>$table->drop(['assigned_by','max_stops','cancelled_at','cancellation_reason']));
        Schema::dropIfExists('order_cancellation_requests'); Schema::dropIfExists('idempotency_records'); Schema::dropIfExists('call_tickets');
    }
};
