<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'payment_status')) {
                $table->string('payment_status', 20)->default('UNPAID')->after('status');
            }
            if (!Schema::hasColumn('orders', 'transaction_id')) {
                $table->string('transaction_id')->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('orders', 'driver_id')) {
                $table->foreignId('driver_id')->nullable()->constrained('employees')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable();
            }
            if (!Schema::hasColumn('orders', 'cancelled_at')) {
                $table->dateTime('cancelled_at')->nullable();
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE orders MODIFY status VARCHAR(32) NOT NULL');
        }

        DB::table('orders')->where('status', 'pending')->update(['status' => 'PENDING_PAYMENT']);
        DB::table('orders')->whereIn('status', ['confirmed', 'in_progress'])->update(['status' => 'PREPARATION']);
        DB::table('orders')->where('status', 'ready')->update(['status' => 'OUT_FOR_DELIVERY']);
        DB::table('orders')->whereIn('status', ['served', 'paid'])->update(['status' => 'DELIVERED']);
        DB::table('orders')->where('status', 'cancelled')->update(['status' => 'CANCELLED']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status ENUM('PENDING_PAYMENT','PREPARATION','OUT_FOR_DELIVERY','DELIVERED','CANCELLED') NOT NULL DEFAULT 'PENDING_PAYMENT'");
        }

        if (Schema::hasColumn('orders', 'transaction_id')) {
            try {
                DB::statement('ALTER TABLE orders ADD UNIQUE INDEX orders_transaction_id_unique (transaction_id)');
            } catch (\Throwable $e) {
                // ignore if index already exists
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE orders MODIFY status VARCHAR(32) NOT NULL');
        }

        DB::table('orders')->where('status', 'PENDING_PAYMENT')->update(['status' => 'pending']);
        DB::table('orders')->where('status', 'PREPARATION')->update(['status' => 'confirmed']);
        DB::table('orders')->where('status', 'OUT_FOR_DELIVERY')->update(['status' => 'ready']);
        DB::table('orders')->where('status', 'DELIVERED')->update(['status' => 'served']);
        DB::table('orders')->where('status', 'CANCELLED')->update(['status' => 'cancelled']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY status ENUM('pending','confirmed','in_progress','ready','served','paid','cancelled') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropUnique(['transaction_id']);
            $table->dropColumn(['payment_status', 'transaction_id', 'cancellation_reason', 'cancelled_at']);
        });
    }
};
