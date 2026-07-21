<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'is_urgent')) {
                $table->boolean('is_urgent')->default(false)->index();
            }
            if (! Schema::hasColumn('orders', 'priority')) {
                $table->string('priority', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('orders', 'expedited_at')) {
                $table->timestamp('expedited_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'expedited_by')) {
                $table->foreignId('expedited_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        Schema::create('order_customer_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('food_rating');
            $table->unsignedTinyInteger('delivery_rating');
            $table->unsignedTinyInteger('speed_rating');
            $table->boolean('contacted')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_customer_experiences');
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'expedited_by')) {
                $table->dropConstrainedForeignId('expedited_by');
            }
            foreach (['expedited_at', 'priority', 'is_urgent'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
