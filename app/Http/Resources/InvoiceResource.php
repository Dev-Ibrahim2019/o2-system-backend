<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paidAmount = $this->whenLoaded(
            'payments',
            fn () => (float) $this->payments->sum('amount'),
            fn () => (float) $this->payments()->sum('amount')
        );

        return [
            'id' => $this->id,
            'number' => $this->number,
            'order_id' => $this->order_id,
            'branch_id' => $this->branch_id,
            'customer_id' => $this->customer_id,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            'paid_amount' => $paidAmount,
            'remaining_amount' => max(0, (float) $this->total - $paidAmount),
            'invoice_date' => $this->invoice_date?->toIso8601String(),
            'notes' => $this->notes,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toIso8601String(),

            // ═══════════════════════════════════════════════════
            //  🖥️ تفاصيل نقطة البيع (POS Details)
            // ═══════════════════════════════════════════════════
            'pos' => [
                'register_id' => $this->pos_register_id,
                'code'        => $this->pos_code,
                'name'        => $this->pos_name,
                'branch'      => $this->whenLoaded('branch', fn () => [
                    'id'   => $this->branch?->id,
                    'name' => $this->branch?->name,
                ]),
            ],

            // ═══════════════════════════════════════════════════
            //  📋 تفاصيل الفاتورة (Invoice Details)
            // ═══════════════════════════════════════════════════
            'details' => [
                'number'         => $this->number,
                'date'           => $this->invoice_date?->format('Y-m-d'),
                'time'           => $this->invoice_date?->format('H:i:s'),
                'currency'       => $this->currency ?? 'ILS',
                'account_number' => $this->account_number,
            ],

            // ═══════════════════════════════════════════════════
            //  🔓 تفاصيل فتح الفاتورة (Open Details)
            // ═══════════════════════════════════════════════════
            'opening' => [
                'user'       => $this->whenLoaded('openedByUser', fn () => [
                    'id'   => $this->openedByUser?->id,
                    'name' => $this->openedByUser?->name,
                ]),
                'pos_name'   => $this->pos_name,
                'date'       => $this->opened_at?->format('Y-m-d'),
                'time'       => $this->opened_at?->format('H:i:s'),
            ],

            // ═══════════════════════════════════════════════════
            //  🔒 تفاصيل إغلاق الفاتورة (Close Details)
            // ═══════════════════════════════════════════════════
            'closing' => $this->closed_at ? [
                'user'       => $this->whenLoaded('closedByUser', fn () => [
                    'id'   => $this->closedByUser?->id,
                    'name' => $this->closedByUser?->name,
                ]),
                'pos_name'   => $this->pos_name,
                'date'       => $this->closed_at?->format('Y-m-d'),
                'time'       => $this->closed_at?->format('H:i:s'),
            ] : null,
        ];
    }
}
