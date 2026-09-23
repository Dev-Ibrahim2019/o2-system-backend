<?php

namespace App\Services\CallCenter;

use App\Models\Order;
use App\Models\ProductionTicket;
use App\Services\Printing\OrderPrintingService;
use Illuminate\Support\Str;

/**
 * تنفيذ الطباعة بالأقسام مع تسجيل نتيجة كل قسم على تذكرته (production_tickets.print_status): طابعة قسم
 * فاصلة بتتسجّل "failed" مع سبب، مش بتضيع التذكرة ولا بتوقف باقي الأقسام، وفيه إعادة طباعة لقسم بعينه.
 * الطباعة نفسها (توجيه الطابعة + الرسم) بتضل بـ OrderPrintingService — هون بس التسجيل والعزل بين الأقسام.
 */
class DepartmentTicketPrinter
{
    public function __construct(private readonly OrderPrintingService $printing) {}

    /**
     * يطبع تذاكر الأقسام اللي لسا ما انطبعت (أو فشلت). بيرجّع نتيجة كل قسم؛ فاضي لو الطباعة التلقائية
     * مطفية بالإعدادات (call-center.print_on_execute) — التذاكر بتضل موجودة وتنطبع يدويًا.
     *
     * @return array<int, array{ticket_id:int, department:?string, success:bool, message:?string}>
     */
    public function printOrder(Order $order): array
    {
        if (! config('call-center.print_on_execute', true)) {
            return [];
        }

        $tickets = $order->tickets()
            ->with(['department', 'ticketItems.orderItem'])
            ->whereIn('status', ['pending', 'preparing'])
            ->where(fn ($q) => $q->whereNull('print_status')->orWhere('print_status', '!=', 'printed'))
            ->get();

        return $tickets->map(fn (ProductionTicket $ticket) => $this->printTicket($order, $ticket))->values()->all();
    }

    public function printTicket(Order $order, ProductionTicket $ticket): array
    {
        try {
            $result = $this->printing->printTicket($order, $ticket) ?? ['success' => true];
        } catch (\Throwable $e) {
            report($e);
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        $success = (bool) ($result['success'] ?? false);
        $message = $success ? null : Str::limit((string) ($result['message'] ?? 'فشلت الطباعة'), 250, '');

        $ticket->update([
            'print_status' => $success ? 'printed' : 'failed',
            'print_error' => $message,
            'printed_at' => $success ? now() : $ticket->printed_at,
            'print_attempts' => min(255, (int) $ticket->print_attempts + 1),
        ]);

        return [
            'ticket_id' => $ticket->id,
            'department' => $ticket->department?->name,
            'success' => $success,
            'message' => $message,
        ];
    }
}
