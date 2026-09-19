<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_tickets', function (Blueprint $table) {
            $table->string('call_type', 30)->nullable()->after('disposition');
            $table->unsignedTinyInteger('satisfaction_rating')->nullable()->after('call_type');
            $table->text('satisfaction_feedback')->nullable()->after('satisfaction_rating');

            $table->index(['branch_id', 'call_type']);
        });
    }

    public function down(): void
    {
        Schema::table('call_tickets', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'call_type']);
            $table->dropColumn(['call_type', 'satisfaction_rating', 'satisfaction_feedback']);
        });
    }
};
