<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a per-item rating to the complaint it was escalated into, if any.
 * One rating spawns at most one complaint — the CRM order pop-up flags it,
 * the complaint then lives in the normal complaints framework (and the
 * customer's complaints tab). Nulled if that complaint is ever deleted; the
 * rating itself stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_feedback', function (Blueprint $table) {
            $table->foreignId('complaint_id')->nullable()->after('notes')
                ->constrained('customer_complaints')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_item_feedback', function (Blueprint $table) {
            $table->dropConstrainedForeignId('complaint_id');
        });
    }
};
