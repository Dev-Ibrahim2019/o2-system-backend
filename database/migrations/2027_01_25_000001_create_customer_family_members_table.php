<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('relationship', ['spouse', 'child', 'parent', 'sibling', 'other']);
            $table->date('birth_date')->nullable();
            // Links this row to the CustomerOccasion its birth_date created,
            // so the sync can find and update/restore/soft-delete the SAME
            // occasion every time instead of duplicating it on every save.
            // occasion_type='birthday' alone (how the customer's own single
            // birthday occasion is found — see CustomerIdentityService::
            // syncBirthdayOccasion()) can't disambiguate here: one customer
            // can have several family members, each with their own birthday
            // occasion under the same occasionable (occasionable_type=
            // Customer, occasionable_id=customer_id, per the family-member
            // feature's own spec — the occasion belongs to the customer,
            // same as every other occasion, not to the family member row).
            $table->foreignId('occasion_id')->nullable()->constrained('customer_occasions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_family_members');
    }
};
