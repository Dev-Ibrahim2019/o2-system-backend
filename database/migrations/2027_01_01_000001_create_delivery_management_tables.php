<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('delivery_zones')) {
            Schema::create('delivery_zones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
                $table->string('code', 50);
                $table->string('name');
                $table->string('city')->nullable();
                $table->string('area')->nullable();
                $table->decimal('base_fee', 12, 3)->default(0);
                $table->decimal('minimum_order_amount', 12, 3)->nullable();
                $table->decimal('free_delivery_threshold', 12, 3)->nullable();
                $table->unsignedSmallInteger('estimated_minutes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->unique(['branch_id', 'code']);
                $table->index(['branch_id', 'is_active']);
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'delivery_zone_id')) {
                $table->foreignId('delivery_zone_id')->nullable()->after('customer_address_id')->constrained('delivery_zones')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'delivery_fee')) {
                $table->decimal('delivery_fee', 12, 3)->nullable()->default(0)->after('engine_discount_amount');
            }
        });

        if (!Schema::hasTable('delivery_trips')) {
            Schema::create('delivery_trips', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained()->restrictOnDelete();
                $table->foreignId('driver_id')->nullable()->constrained('employees')->restrictOnDelete();
                $table->string('number')->unique();
                $table->string('status', 20)->default('draft');
                $table->text('notes')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['branch_id', 'status']);
            });
        }

        if (!Schema::hasTable('delivery_trip_stops')) {
            Schema::create('delivery_trip_stops', function (Blueprint $table) {
                $table->id();
                $table->foreignId('delivery_trip_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->constrained()->restrictOnDelete();
                $table->unsignedSmallInteger('sequence');
                $table->string('status', 20)->default('pending');
                $table->json('delivery_address_snapshot')->nullable();
                $table->timestamp('arrived_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->text('notes')->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamps();
                $table->unique(['delivery_trip_id', 'order_id']);
                $table->unique(['delivery_trip_id', 'sequence']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_trip_stops');
        Schema::dropIfExists('delivery_trips');
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('delivery_zone_id'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_fee'));
        Schema::dropIfExists('delivery_zones');
    }
};