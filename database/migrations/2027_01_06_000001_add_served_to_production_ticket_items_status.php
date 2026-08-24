<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * production_ticket_items.status is missing 'served', unlike its parent
     * production_tickets.status and order_items.status which both already
     * have it. ProductionTicketController::markServed() writes 'served' to
     * all three — without this value the write to production_ticket_items
     * fails ("Data truncated for column 'status'"), rolling back the whole
     * transaction and silently keeping the ticket at 'ready' forever, which
     * blocks the order from ever reaching orders.status = 'served'.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        if (! Schema::hasTable('production_ticket_items')) {
            return;
        }

        DB::statement("ALTER TABLE production_ticket_items MODIFY status ENUM('pending', 'preparing', 'ready', 'served', 'cancelled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        if (! Schema::hasTable('production_ticket_items')) {
            return;
        }

        DB::statement("ALTER TABLE production_ticket_items MODIFY status ENUM('pending', 'preparing', 'ready', 'cancelled') NOT NULL DEFAULT 'pending'");
    }
};
