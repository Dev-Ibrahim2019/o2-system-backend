<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PaymentMethodController — CRUD for configurable payment methods
 *
 * Each payment method links to a financial account.
 * Used by the SettlementPanel in the POS cashier.
 */
class PaymentMethodController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $methods = PaymentMethod::with('account:id,code,name')
            ->when($request->type, fn($q, $type) => $q->where('type', $type))
            ->when($request->has('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->get()
            ->map(fn($m) => [
                'id'         => $m->id,
                'name'       => $m->name,
                'name_en'    => $m->name_en,
                'type'       => $m->type,
                'account'    => $m->account ? [
                    'id'   => $m->account->id,
                    'code' => $m->account->code,
                    'name' => $m->account->name,
                ] : null,
                'is_active'  => $m->is_active,
                'is_entity'  => $m->is_entity,
                'sort_order' => $m->sort_order,
            ]);

        return $this->success('Payment methods fetched', $methods);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'name_en'    => 'nullable|string|max:255',
            'type'       => 'required|string|in:cash,bank,card,wallet,customer,employee,supplier',
            'account_id' => 'required|exists:accounts,id',
            'is_active'  => 'boolean',
            'is_entity'  => 'boolean',
            'sort_order' => 'integer|min:0',
            'description' => 'nullable|string',
        ]);

        $method = PaymentMethod::create($validated);

        return $this->success('Payment method created', $method->load('account:id,code,name'), 201);
    }

    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        return $this->success('Payment method fetched', $paymentMethod->load('account:id,code,name'));
    }

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'string|max:255',
            'name_en'    => 'nullable|string|max:255',
            'type'       => 'string|in:cash,bank,card,wallet,customer,employee,supplier',
            'account_id' => 'exists:accounts,id',
            'is_active'  => 'boolean',
            'is_entity'  => 'boolean',
            'sort_order' => 'integer|min:0',
            'description' => 'nullable|string',
        ]);

        $paymentMethod->update($validated);

        return $this->success('Payment method updated', $paymentMethod->fresh()->load('account:id,code,name'));
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->delete();

        return $this->success('Payment method deleted');
    }
}
