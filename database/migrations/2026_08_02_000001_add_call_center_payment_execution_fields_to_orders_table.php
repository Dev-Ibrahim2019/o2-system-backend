<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_policy', ['manual_confirmation', 'instant_debit'])
                ->nullable()->after('status')->index();
            $table->enum('payment_status', [
                'unpaid',
                'awaiting_confirmation',
                'processing',
                'paid',
                'failed',
                'refunded',
            ])->nullable()->after('payment_policy')->index();
            $table->enum('kitchen_release_status', [
                'held',
                'releasing',
                'released',
                'release_failed',
            ])->nullable()->after('payment_status')->index();
            $table->timestamp('kitchen_released_at')->nullable()->after('kitchen_release_status');
            $table->foreignId('kitchen_released_by')
                ->nullable()
                ->after('kitchen_released_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['kitchen_released_by']);
            $table->dropColumn([
                'payment_policy',
                'payment_status',
                'kitchen_release_status',
                'kitchen_released_at',
                'kitchen_released_by',
            ]);
        });
    }
};
