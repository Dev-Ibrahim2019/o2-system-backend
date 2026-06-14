<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('category')->nullable()->after('status')->comment('local | international | service');
            $table->string('currency', 3)->default('ILS')->after('category');
            $table->string('mobile')->nullable()->after('phone');
            $table->string('city')->nullable()->after('address');
            $table->decimal('credit_limit', 15, 3)->default(0)->after('city');
            $table->string('payment_terms')->nullable()->after('credit_limit')->comment('immediate | net15 | net30 | net60 | net90');
            $table->decimal('opening_balance', 15, 3)->default(0)->after('payment_terms');
            $table->boolean('is_opening_balance_posted')->default(false)->after('opening_balance');
            $table->text('notes')->nullable()->after('is_opening_balance_posted');
            $table->string('gps_link')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'category',
                'currency',
                'mobile',
                'city',
                'credit_limit',
                'payment_terms',
                'opening_balance',
                'is_opening_balance_posted',
                'notes',
                'gps_link',
            ]);
        });
    }
};
