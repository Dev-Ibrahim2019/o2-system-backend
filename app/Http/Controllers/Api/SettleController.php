<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Order;
use App\Services\Accounting\RegisterResolver;
use App\Services\Accounting\SettlementEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SettleController — Settlement & Payment Routing Engine Endpoint
 *
 * POST /orders/{order}/settle  →  Settle an order with mixed payments
 * GET  /orders/{order}/settlement → Get settlement details
 */
class SettleController extends ApiController
{
    public function __construct(
        private readonly SettlementEngine $settlementEngine,
        private readonly RegisterResolver $registerResolver,
    ) {}

    /**
     * POST /orders/{order}/settle
     *
     * Request body:
     * {
     *   "payments": [
     *     {
     *       "payment_method_id": 1,   // ID from payment_methods table
     *       "amount": 200.00,
     *       "reference_number": "REF123",  // optional, for wallet/bank transfers
     *       "entity_type": "customer",     // required for entity methods
     *       "entity_id": 5                 // required for entity methods
     *     },
     *     {
     *       "payment_method_id": 5,   // customer account
     *       "amount": 300.00,
     *       "entity_type": "customer",
     *       "entity_id": 12
     *     }
     *   ]
     * }
     */
    public function settle(Request $request, Order $order): JsonResponse
    {
        // Validate order can be settled
        if (in_array($order->status, ['cancelled', 'paid'], true)) {
            return $this->error('لا يمكن تسوية هذا الطلب في حالته الحالية.', 422);
        }

        $validated = $request->validate([
            'payments'                       => 'required|array|min:1',
            'payments.*.payment_method_id'   => 'required|exists:payment_methods,id',
            'payments.*.amount'              => 'required|numeric|min:0.01',
            'payments.*.reference_number'    => 'nullable|string|max:255',
            'payments.*.entity_type'         => 'nullable|string|in:customer,employee,supplier',
            'payments.*.entity_id'           => 'nullable|integer|min:1',
        ]);

        $register = $this->registerResolver->resolveFromRequest($request);

        try {
            $result = $this->settlementEngine->settle($order, $validated['payments'], $register);

            return $this->success('تمت تسوية الفاتورة بنجاح', [
                'order'       => $result['order'],
                'transaction' => [
                    'id'                 => $result['transaction']->id,
                    'transaction_number' => $result['transaction']->transaction_number,
                    'status'             => $result['transaction']->status,
                    'entries'            => $result['transaction']->entries->map(fn($e) => [
                        'account'    => [
                            'id'   => $e->account->id,
                            'code' => $e->account->code,
                            'name' => $e->account->name,
                        ],
                        'debit'      => (float) $e->debit,
                        'credit'     => (float) $e->credit,
                        'subledger'  => $e->subledger_type ? [
                            'type' => $e->subledger_type,
                            'id'   => $e->subledger_id,
                        ] : null,
                    ]),
                ],
                'payments'    => $result['invoice']->payments,
                'invoice'     => $result['invoice'],
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('فشلت تسوية الفاتورة: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /orders/{order}/settlement
     */
    public function show(Order $order): JsonResponse
    {
        try {
            $details = $this->settlementEngine->getSettlementDetails($order);

            return $this->success('Settlement details fetched', $details);
        } catch (\Throwable $e) {
            return $this->error('فشل جلب تفاصيل التسوية: ' . $e->getMessage(), 500);
        }
    }
}
