<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentExtractionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_payment_rejects_call_center_and_keeps_pos_available(): void
    {
        [$user, $callCenterInvoice] = $this->invoice('call_center');
        [, $posInvoice] = $this->invoice('pos', $user);
        $method = $this->paymentMethod();

        $this->actingAs($user)->postJson("/api/invoices/{$callCenterInvoice->id}/payments", [
            'method' => 'bank', 'payment_method_id' => $method->id, 'amount' => 100,
        ])->assertStatus(422)->assertJsonPath(
            'message', 'دفعات الكول سنتر تُسجّل حصرًا عبر خدمة تنفيذ طلبات الكول سنتر.'
        );

        $this->actingAs($user)->postJson("/api/invoices/{$posInvoice->id}/payments", [
            'method' => 'bank', 'payment_method_id' => $method->id, 'amount' => 100,
        ])->assertCreated();
        $this->assertSame('paid', $posInvoice->fresh()->status);
    }

    public function test_pos_partial_and_full_payments_keep_previous_status_and_posting_behavior(): void
    {
        [$user, $invoice] = $this->invoice('pos');
        $method = $this->paymentMethod();

        $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/payments", [
            'method' => 'bank', 'payment_method_id' => $method->id, 'amount' => 40,
        ])->assertCreated();
        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame('pending', $invoice->order->fresh()->status);

        $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/payments", [
            'method' => 'bank', 'payment_method_id' => $method->id, 'amount' => 60,
        ])->assertCreated();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('paid', $invoice->order->fresh()->status);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_pos_confirm_still_creates_production_tickets_and_confirms_order(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $order = Order::create([
            'order_number' => uniqid('ORD-POS-'), 'branch_id' => $branch->id,
            'order_type' => 'takeaway', 'source' => 'pos', 'status' => 'pending',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'item_id' => $item->id, 'department_id' => $item->department_id,
            'item_name' => $item->name, 'quantity' => 1, 'price' => 10, 'total' => 10, 'status' => 'pending',
        ]);

        $response = app(\App\Http\Controllers\Api\OrderController::class)->confirm($order);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertDatabaseCount('production_tickets', 1);
    }

    public function test_pos_legacy_payment_method_values_remain_accepted(): void
    {
        $methods = ['cash', 'card', 'credit_card', 'bank', 'wallet', 'app', 'account', 'mixed', 'customer', 'employee', 'supplier'];
        $user = null;

        foreach ($methods as $method) {
            [$user, $invoice] = $this->invoice('pos', $user);
            $this->actingAs($user)->postJson("/api/invoices/{$invoice->id}/payments", [
                'method' => $method,
                'amount' => 1,
            ])->assertCreated();
            $this->assertSame('partial', $invoice->fresh()->status, "Failed method: {$method}");
        }
    }

    private function invoice(string $source, ?User $user = null): array
    {
        $branch = $user?->branch ?? Branch::factory()->create();
        $user ??= User::factory()->create(['branch_id' => $branch->id]);
        $order = Order::create([
            'order_number' => uniqid('ORD-'), 'branch_id' => $branch->id,
            'order_type' => 'takeaway', 'source' => $source, 'status' => 'pending',
            'subtotal' => 100, 'total' => 100,
        ]);
        $invoice = Invoice::create([
            'number' => uniqid('INV-'), 'order_id' => $order->id, 'branch_id' => $branch->id,
            'status' => 'draft', 'subtotal' => 100, 'discount' => 0, 'total' => 100, 'invoice_date' => now(),
        ]);
        Account::firstOrCreate(['code' => '4'], [
            'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit',
            'allow_posting' => false, 'is_active' => true,
        ]);
        return [$user, $invoice];
    }

    private function paymentMethod(): PaymentMethod
    {
        $account = Account::create([
            'name' => 'Bank '.uniqid(), 'code' => uniqid('BANK-'), 'type' => 'asset',
            'normal_balance' => 'debit', 'allow_posting' => true, 'is_active' => true,
        ]);
        return PaymentMethod::create([
            'name' => 'Bank', 'type' => 'bank', 'account_id' => $account->id,
            'is_active' => true, 'is_entity' => false,
        ]);
    }
}
