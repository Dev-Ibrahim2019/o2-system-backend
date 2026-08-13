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
            DB::statement('ALTER TABLE orders MODIFY status VARCHAR(50) NOT NULL DEFAULT "PENDING_PAYMENT"');
        }

        $statusMap = [
            'pending' => 'PENDING_PAYMENT',
            'pending_payment' => 'PENDING_PAYMENT',
            'confirmed' => 'PREPARATION',
            'in_progress' => 'PREPARATION',
            'ready' => 'OUT_FOR_DELIVERY',
            'served' => 'DELIVERED',
            'paid' => 'DELIVERED',
            'cancelled' => 'CANCELLED',
            'pending_confirmation' => 'PENDING_PAYMENT',
            'PENDING_CONFIRMATION' => 'PENDING_PAYMENT',
        ];

        foreach ($statusMap as $from => $to) {
            DB::table('orders')->where('status', $from)->update(['status' => $to]);
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
            DB::statement('ALTER TABLE orders MODIFY status VARCHAR(50) NOT NULL DEFAULT "PENDING_PAYMENT"');
        }

        $statusMap = [
            'PENDING_PAYMENT' => 'pending',
            'PREPARATION' => 'confirmed',
            'OUT_FOR_DELIVERY' => 'ready',
            'DELIVERED' => 'served',
            'CANCELLED' => 'cancelled',
            'PENDING_CONFIRMATION' => 'pending_confirmation',
        ];

        foreach ($statusMap as $from => $to) {
            DB::table('orders')->where('status', $from)->update(['status' => $to]);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropUnique(['transaction_id']);
            $table->dropColumn(['payment_status', 'transaction_id', 'cancellation_reason', 'cancelled_at']);
        });
    }
};
