<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE sales_invoices MODIFY COLUMN status ENUM('draft', 'awaiting_approval', 'awaiting_payment', 'partial', 'paid', 'cancelled') DEFAULT 'draft'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE sales_invoices MODIFY COLUMN status ENUM('draft', 'awaiting_approval', 'awaiting_payment', 'paid', 'cancelled') DEFAULT 'draft'");
    }
};
