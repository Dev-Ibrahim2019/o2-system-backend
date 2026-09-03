<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One customer opted out of one rule — checked before that rule can win. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_rule_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('loyalty_rules')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['rule_id', 'customer_id'], 'lre_rule_customer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rule_exclusions');
    }
};
