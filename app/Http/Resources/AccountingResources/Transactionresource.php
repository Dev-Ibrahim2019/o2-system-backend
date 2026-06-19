<?php

namespace App\Http\Resources\AccountingResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\AccountingResources\EntryResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'transaction_number' => $this->transaction_number,
            'date'               => $this->date?->format('Y-m-d'),
            'reference'          => $this->reference,
            'type'               => $this->type,
            'type_label'         => $this->getTypeLabel(),
            'status'             => $this->status,
            'status_label'       => $this->getStatusLabel(),
            'description'        => $this->description,
            'notes'              => $this->notes,

            // ── الإجماليات — تُحسب دائماً من الداتابيز ──────────────────────
            'total_debit'    => (float) $this->entries()->sum('debit'),
            'total_credit'   => (float) $this->entries()->sum('credit'),

            // ✅ عدد الأسطر — يُجلب من الداتابيز ويُرسل مع كل قيد
            'entries_count'  => (int) $this->entries()->count(),

            'is_balanced'   => $this->isBalanced(),
            'is_editable'   => $this->isEditable(),

            // ✅ Polymorphic source
            'source_type'  => $this->source_type,
            'source_id'    => $this->source_id,
            'source_label' => $this->source_label,
            'source'       => $this->when(
                $this->relationLoaded('source') && $this->source,
                fn() => $this->buildSourcePayload()
            ),

            // ── العلاقات ────────────────────────────────────────────────────
            // ✅ entries تُحمَّل فقط عند طلب show() أو عند تحميلها صراحةً
            //    في index() لا تُحمَّل (لتسريع القائمة) — entries_count يكفي
            'entries' => EntryResource::collection($this->whenLoaded('entries')),

            'branch' => $this->whenLoaded('branch', fn() => [
                'id'   => $this->branch->id,
                'name' => $this->branch->name,
            ]),

            'user' => $this->whenLoaded('user', fn() => [
                'id'   => $this->user->id,
                'name' => $this->user->name,
            ]),

            'posted_at'  => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * بناء payload المصدر بحسب نوعه
     */
    private function buildSourcePayload(): array
    {
        $source = $this->source;
        $label  = $this->source_label;

        return match ($label) {
            'Order' => [
                'type'         => 'Order',
                'id'           => $source->id,
                'order_number' => $source->order_number,
                'total'        => (float) $source->total,
                'status'       => $source->status,
            ],
            'Employee' => [
                'type'        => 'Employee',
                'id'          => $source->id,
                'name'        => $source->name,
                'employee_id' => $source->employeeId,
            ],
            default => [
                'type' => $label,
                'id'   => $source->id,
            ],
        };
    }

    private function getTypeLabel(): string
    {
        return match ($this->type) {
            'sale'       => 'مبيعات',
            'purchase'   => 'مشتريات',
            'salary'     => 'رواتب',
            'expense'    => 'مصروف',
            'receipt'    => 'قبض',
            'payment'    => 'دفع',
            'journal'    => 'قيد يومية',
            'opening'    => 'رصيد افتتاحي',
            'adjustment' => 'تسوية',
            default      => $this->type,
        };
    }

    private function getStatusLabel(): string
    {
        return match ($this->status) {
            'draft'     => 'مسودة',
            'posted'    => 'مرحَّل',
            'cancelled' => 'ملغي',
            default     => $this->status,
        };
    }
}