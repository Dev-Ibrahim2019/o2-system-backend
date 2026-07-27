<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            if (! Schema::hasColumn('discounts', 'apply_strategy')) {
                $table->string('apply_strategy', 30)
                    ->default('per_quantity')
                    ->after('discount_type')
                    ->index();
            }
        });

        if (! Schema::hasTable('discount_exclusions')) {
            Schema::create('discount_exclusions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('discount_id')->constrained('discounts')->cascadeOnDelete();
                $table->string('target_type', 50);
                $table->unsignedBigInteger('target_id')->nullable();
                $table->timestamps();

                $table->unique(['discount_id', 'target_type', 'target_id'], 'uq_discount_exclusion_target');
                $table->index(['target_type', 'target_id'], 'idx_discount_exclusion_lookup');
            });
        }

        Schema::table('discount_targets', function (Blueprint $table) {
            $table->unique(['discount_id', 'target_type', 'target_id'], 'uq_discount_target_once');
        });

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                if (! Schema::hasColumn('invoice_items', 'discount_apply_strategy')) {
                    $table->string('discount_apply_strategy', 30)->nullable()->after('discount_id');
                }
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                if (! Schema::hasColumn('order_items', 'discount_apply_strategy')) {
                    $table->string('discount_apply_strategy', 30)->nullable()->after('discount_id');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'discount_apply_strategy')) {
                $table->dropColumn('discount_apply_strategy');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'discount_apply_strategy')) {
                $table->dropColumn('discount_apply_strategy');
            }
        });

        Schema::table('discount_targets', function (Blueprint $table) {
            $table->dropUnique('uq_discount_target_once');
        });

        Schema::dropIfExists('discount_exclusions');

        Schema::table('discounts', function (Blueprint $table) {
            if (Schema::hasColumn('discounts', 'apply_strategy')) {
                $table->dropColumn('apply_strategy');
            }
        });
    }
};
