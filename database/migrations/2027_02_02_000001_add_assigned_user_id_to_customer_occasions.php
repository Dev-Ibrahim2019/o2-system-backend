<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "من المسؤول عن هذه المناسبة" — a CRM/sales user (never an Employee row;
 * this is who follows up in the CRM, the same distinction
 * customer_complaints.assigned_user_id already draws over its older,
 * Employee-based assigned_to column). Nullable: an occasion is useful
 * unassigned, exactly like an unassigned complaint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_occasions', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_occasions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
        });
    }
};
