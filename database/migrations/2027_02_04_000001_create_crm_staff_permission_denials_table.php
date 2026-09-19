<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The explicit "deny" layer over role permissions, for one CRM staff member.
 *
 * Spatie's own model only ever unions permissions (role ∪ direct) — there is
 * no built-in way to take a permission back from one person while their role
 * keeps it for everyone else. This table is that missing negative layer:
 * User::hasPermissionTo() (overridden in app/Models/User.php) checks it
 * before deferring to Spatie's normal role/direct resolution, so a denial
 * here wins over both the role and any direct grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_staff_permission_denials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_staff_permission_denials');
    }
};
