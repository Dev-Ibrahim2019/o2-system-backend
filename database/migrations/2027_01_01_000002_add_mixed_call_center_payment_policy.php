<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->changePolicyEnum(['manual_confirmation', 'instant_debit', 'mixed']);
    }

    public function down(): void
    {
        if (DB::table('orders')->where('payment_policy', 'mixed')->exists()) {
            throw new \RuntimeException('Cannot remove the mixed payment policy while orders still use it.');
        }

        $this->changePolicyEnum(['manual_confirmation', 'instant_debit']);
    }

    private function changePolicyEnum(array $values): void
    {
        Schema::table('orders', function (Blueprint $table) use ($values) {
            $table->enum('payment_policy', $values)->nullable()->change();
        });
    }
};
