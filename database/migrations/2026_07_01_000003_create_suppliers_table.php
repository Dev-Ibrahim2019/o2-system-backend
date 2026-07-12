<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('code')->unique();
            $table->string('tax_number')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('gps_link')->nullable();
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active');
            $table->string('category')->nullable()->comment('local | international | service');
            $table->string('currency', 3)->default('ILS');
            $table->decimal('credit_limit', 15, 3)->default(0);
            $table->string('payment_terms')->nullable()->comment('immediate | net15 | net30 | net60 | net90');
            $table->decimal('opening_balance', 15, 3)->default(0);
            $table->boolean('is_opening_balance_posted')->default(false);
            $table->text('notes')->nullable();

            // الحساب المحاسبي (2110-xxx)
            $table->foreignId('account_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete();

            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
