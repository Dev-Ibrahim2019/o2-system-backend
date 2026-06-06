<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('code')->unique();
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense'])->default('asset');
            $table->enum('normal_balance', ['debit', 'credit'])->default('debit');

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('accounts')
                ->nullOnDelete();

            $table->unsignedTinyInteger('level')->default(1);
            $table->boolean('allow_posting')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);

            // Entity relationship
            $table->enum('entity_type', ['employee', 'customer', 'supplier', 'branch'])->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('sub_type')->nullable();

            $table->string('currency')->default('SAR');
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->json('meta')->nullable();
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['code']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['branch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique('accounts_entity_unique');
        });
    }
};