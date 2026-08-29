<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('currency', 8)->default('ILS')->after('total');
            $table->decimal('exchange_rate', 12, 6)->default(1)->after('currency');
            $table->string('customer_address', 255)->nullable()->after('customer_address_id');
            $table->timestamp('scheduled_at')->nullable()->after('customer_address');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate', 'customer_address', 'scheduled_at']);
        });
    }
};
