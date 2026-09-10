<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CRM assignee, as a real login account.
 *
 * customer_complaints.assigned_to points at `employees`, a table with no link
 * to `users` — so "assign this to me", "notify the assignee", "which agent
 * resolved it" were all impossible for the people who actually work complaints
 * in the CRM (they log in as users, not employees).
 *
 * assigned_user_id is that missing link. The Call Center keeps using
 * assigned_to untouched; the CRM screens move onto this column. Both can hold
 * a value on the same row without conflict — they answer different questions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->after('assigned_to')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
        });
    }
};
