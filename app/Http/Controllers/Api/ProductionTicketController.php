<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\IndexProductionTicketRequest;
use App\Http\Resources\ProductionTicketResource;
use App\Models\Order;
use App\Models\ProductionTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductionTicketController extends ApiController
{
    /**
     * عرض تذاكر قسم (KDS) — مرتبة حسب الأولوية ثم وقت الإنشاء
     */
    public function index(IndexProductionTicketRequest $request): JsonResponse
    {
        $data = $request->validated();

        $tickets = ProductionTicket::with([
            'order:id,order_number,order_type,table_number,note,status',
            'department:id,name,color,icon',
            'ticketItems.orderItem',
        ])
            ->where('department_id', $data['department_id'])
            ->when(
                $request->filled('status'),
                fn ($q) => $q->whereIn('status', explode(',', (string) $request->status))
            )
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'paid']))
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get();

        return $this->success('Tickets fetched', ProductionTicketResource::collection($tickets));
    }

    /**
     * بدء التحضير — pending → preparing
     */
    public function startPreparing(ProductionTicket $ticket): JsonResponse
    {
        if (! in_array($ticket->status, ['pending'], true)) {
            return $this->error('لا يمكن بدء تحضير هذه التذكرة.', 422);
        }

        try {
            DB::transaction(function () use ($ticket) {
                $ticket->update([
                    'status' => 'preparing',
                    'started_at' => $ticket->started_at ?? now(),
                ]);

                $ticket->ticketItems()->where('status', 'pending')->update(['status' => 'preparing']);

                $order = $ticket->order;
                if (in_array($order->status, ['confirmed'], true)) {
                    $order->update(['status' => 'in_progress']);
                }
            });

            return $this->success(
                'بدأ التحضير',
                new ProductionTicketResource($ticket->fresh()->load(['order', 'department', 'ticketItems.orderItem']))
            );
        } catch (\Throwable $e) {
            return $this->error('فشل تحديث التذكرة: '.$e->getMessage(), 500);
        }
    }

    /**
     * جاهز — preparing → ready + فحص اكتمال الطلب
     */
    public function markReady(ProductionTicket $ticket): JsonResponse
    {
        if (! in_array($ticket->status, ['pending', 'preparing'], true)) {
            return $this->error('لا يمكن تعليم هذه التذكرة كجاهزة.', 422);
        }

        try {
            DB::transaction(function () use ($ticket) {
                $ticket->update([
                    'status' => 'ready',
                    'ready_at' => now(),
                    'started_at' => $ticket->started_at ?? now(),
                ]);

                $ticket->ticketItems()->update(['status' => 'ready']);

                $this->syncOrderReadyStatus($ticket->order);
            });

            return $this->success(
                'التذكرة جاهزة',
                new ProductionTicketResource($ticket->fresh()->load(['order', 'department', 'ticketItems.orderItem']))
            );
        } catch (\Throwable $e) {
            return $this->error('فشل تحديث التذكرة: '.$e->getMessage(), 500);
        }
    }

    /**
     * تسليم — ready → served + فحص اكتمال تسليم الطلب
     */
    public function markServed(ProductionTicket $ticket): JsonResponse
    {
        if ($ticket->status !== 'ready') {
            return $this->error('يجب أن تكون التذكرة جاهزة قبل التسليم.', 422);
        }

        try {
            DB::transaction(function () use ($ticket) {
                $ticket->update([
                    'status' => 'served',
                    'served_at' => now(),
                ]);

                $ticket->ticketItems()->update(['status' => 'served']);

                $this->syncOrderServedStatus($ticket->order);
            });

            return $this->success(
                'تم تسليم التذكرة',
                new ProductionTicketResource($ticket->fresh()->load(['order', 'department', 'ticketItems.orderItem']))
            );
        } catch (\Throwable $e) {
            return $this->error('فشل تحديث التذكرة: '.$e->getMessage(), 500);
        }
    }

    /** إذا كل التذاكر ready (غير ملغاة) → الطلب ready */
    private function syncOrderReadyStatus(Order $order): void
    {
        $pendingTickets = $order->tickets()
            ->whereNotIn('status', ['ready', 'served', 'cancelled'])
            ->exists();

        if (! $pendingTickets && in_array($order->status, ['confirmed', 'in_progress'], true)) {
            $order->update(['status' => 'ready']);
            $order->items()->whereNotIn('status', ['cancelled'])->update(['status' => 'ready']);
        }
    }

    /** إذا كل التذاكر served → الطلب served */
    private function syncOrderServedStatus(Order $order): void
    {
        $notServed = $order->tickets()
            ->whereNotIn('status', ['served', 'cancelled'])
            ->exists();

        if (! $notServed && in_array($order->status, ['ready', 'in_progress', 'confirmed'], true)) {
            $order->update(['status' => 'served']);
            $order->items()->whereNotIn('status', ['cancelled'])->update(['status' => 'served']);
        }
    }
}
