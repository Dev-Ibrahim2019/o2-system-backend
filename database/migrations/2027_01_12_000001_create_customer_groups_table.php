<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company, family or agency that several customers belong to.
 *
 * The business classification (retail / wholesale / corporate / government /
 * service) belongs here, not on the individual. It was previously stored on
 * `customers.category`, sharing one column with the Call Center's engagement
 * tags (regular / vip / follow_up / …) — two unrelated vocabularies with no
 * database constraint keeping them apart, and a hand-written `regular → retail`
 * translation in CustomerFinancialController papering over the collision.
 *
 * Scope note: group-level loyalty and group discounts are deliberately NOT
 * modelled here. This table is the foundation they will sit on later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // A real enum this time. The old column's whole problem was that
            // nothing at the database level said which values were legal.
            $table->enum('group_type', ['retail', 'wholesale', 'corporate', 'government', 'service']);

            $table->timestamps();

            $table->index('group_type', 'customer_groups_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_groups');
    }
};
