<?php
// app/Http/Controllers/Api/ProductionTicketController.php
//
// شاشة المطبخ/البار: جلب التذاكر + تحديث حالة الصنف/التذكرة

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\ProductionTicketResource;
use App\Models\ProductionTicket;
use App\Models\ProductionTicketItem;
use Illuminate\Http\Request;

class ProductionTicketController extends ApiController
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /production-tickets
    // جلب التذاكر — يدعم فلتر department_id و status
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $tickets = ProductionTicket::with([
            'order:id,order_number,order_type,table_number,note',
            'department:id,name,color,icon',
            'ticketItems',
        ])
            ->when($request->department_id, fn($q) => $q->where('department_id', $request->department_id))
            ->when($request->status,        fn($q) => $q->whereIn('status', explode(',', $request->status)))
            ->whereHas('order', fn($q) => $q->whereNotIn('status', ['cancelled', 'paid']))
            ->orderBy('created_at')
            ->get();

        return $this->success('Tickets fetched', ProductionTicketResource::collection($tickets));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /production-tickets/{ticket}/status
    // تحديث حالة التذكرة الكاملة (pending → preparing → ready)
    // ─────────────────────────────────────────────────────────────────────────
    public function updateStatus(Request $request, ProductionTicket $ticket)
    {
        $data = $request->validate([
            'status' => 'required|in:pending,preparing,ready,cancelled',
        ]);

        $updates = ['status' => $data['status']];

        if ($data['status'] === 'preparing' && !$ticket->started_at) {
            $updates['started_at'] = now();
            // تحديث جميع الأصناف إلى preparing
            $ticket->ticketItems()->where('status', 'pending')->update(['status' => 'preparing']);
        }

        if ($data['status'] === 'ready') {
            $updates['completed_at'] = now();
            // تحديث جميع الأصناف إلى ready
            $ticket->ticketItems()->update(['status' => 'ready']);
            // تحقق هل جميع تذاكر الطلب جاهزة → حدّث الطلب
            $this->checkAndUpdateOrderStatus($ticket);
        }

        $ticket->update($updates);

        return $this->success(
            'Ticket status updated',
            new ProductionTicketResource($ticket->fresh()->load(['order', 'department', 'ticketItems']))
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /production-tickets/{ticket}/items/{item}/status
    // تحديث حالة صنف واحد داخل التذكرة
    // ─────────────────────────────────────────────────────────────────────────
    public function updateItemStatus(Request $request, ProductionTicket $ticket, ProductionTicketItem $item)
    {
        if ($item->ticket_id !== $ticket->id) {
            return $this->error('Item does not belong to this ticket.', 422);
        }

        $data = $request->validate([
            'status' => 'required|in:pending,preparing,ready',
        ]);

        $item->update(['status' => $data['status']]);

        // إذا كانت التذكرة لا تزال pending وبدأ صنف → preparing
        if ($data['status'] === 'preparing' && $ticket->status === 'pending') {
            $ticket->update(['status' => 'preparing', 'started_at' => $ticket->started_at ?? now()]);
        }

        // إذا كل الأصناف جاهزة → التذكرة جاهزة
        if ($ticket->ticketItems()->where('status', '!=', 'ready')->doesntExist()) {
            $ticket->update(['status' => 'ready', 'completed_at' => now()]);
            $this->checkAndUpdateOrderStatus($ticket);
        }

        return $this->success('Item status updated', [
            'ticket_item' => $item->fresh(),
            'ticket_status' => $ticket->fresh()->status,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تحقق هل جميع تذاكر الطلب جاهزة → حدّث الطلب لـ ready
    // ─────────────────────────────────────────────────────────────────────────
    private function checkAndUpdateOrderStatus(ProductionTicket $ticket): void
    {
        $order = $ticket->order;
        $allReady = $order->tickets()
            ->whereNotIn('status', ['ready', 'cancelled'])
            ->doesntExist();

        if ($allReady) {
            $order->update(['status' => 'ready']);
        }
    }
}
