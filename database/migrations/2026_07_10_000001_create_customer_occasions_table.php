<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_occasions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('occasion_type', 50); // birthday, anniversary, company_founding, special, reminder
            $table->string('title', 255);
            $table->date('date');
            $table->boolean('repeats_annually')->default(true);
            $table->text('notes')->nullable();
            $table->string('preferred_contact_method', 50)->nullable(); // call, sms, email, whatsapp
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_occasions');
    }
};
