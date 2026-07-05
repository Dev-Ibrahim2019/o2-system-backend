<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\DiscountSetting;
use App\Models\DiscountTarget;
use App\Models\Entry;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductionTicket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Invoice\InvoiceFromOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DiscountAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDiscountSettings();
        $this->seedAccounts();
        $this->seedPaymentMethods();
    }

    private function seedDiscountSettings(): void
    {
        DiscountSetting::set('sales_discounts_account_code', '4120');
        DiscountSetting::set('max_discount_percent', '100');
        DiscountSetting::set('allow_compound_discounts', 'false');
    }

    private function seedAccounts(): void
    {
        $revenueParent = Account::create([
            'code' => '4',
            'name' => 'الإيرادات',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'level' => 1,
            'allow_posting' => false,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'code' => '4110',
            'name' => 'إيرادات المبيعات',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'level' => 2,
            'parent_id' => $revenueParent->id,
            'allow_posting' => true,
            'is_active' => true,
            'is_system' => true,
        ]);

        $assets = Account::create([
            'code' => '1',
            'name' => 'الأصول',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'level' => 1,
            'allow_posting' => false,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'code' => '11101',
            'name' => 'صندوق الإيرادات النقدي',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'level' => 4,
            'parent_id' => $assets->id,
            'allow_posting' => true,
            'is_active' => true,
            'is_system' => true,
        ]);
    }

    private function seedPaymentMethods(): void
    {
        PaymentMethod::create([
            'name' => 'نقداً',
            'type' => 'cash',
            'account_id' => Account::where('code', '11101')->value('id'),
            'is_active' => true,
            'is_entity' => false,
            'sort_order' => 1,
        ]);
    }

    private function createOrderWithItem(float $price = 100, float $manualDiscount = 10): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $order = Order::create([
            'order_number' => Order::generateOrderNumber(),
            'branch_id' => $branch->id,
            'cashier_id' => null,
            'order_type' => 'takeaway',
            'status' => 'confirmed',
            'subtotal' => $price,
            'discount_value' => $manualDiscount,
            'discount_type' => 'amount',
            'discount_amount' => $manualDiscount,
            'total' => $price - $manualDiscount,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => 1,
            'department_id' => null,
            'item_name' => 'Test Item',
            'price' => $price,
            'quantity' => 1,
            'total' => $price,
            'status' => 'pending',
        ]);

        ProductionTicket::create([
            'order_id' => $order->id,
            'department_id' => null,
            'ticket_number' => 'TKT-001',
            'status' => 'pending',
        ]);

        return $order;
    }

    #[Test]
    public function it_auto_creates_sales_discounts_account_4120(): void
    {
        $this->assertNull(Account::where('code', '4120')->first());

        $provisioner = app(\App\Services\Accounting\SystemAccountProvisioner::class);
        $account = $provisioner->ensureSalesDiscountsAccount();

        $this->assertEquals('4120', $account->code);
        $this->assertTrue($account->allow_posting);
    }

    #[Test]
    public function it_creates_balanced_journal_for_manual_discount_cash_sale(): void
    {
        $order = $this->createOrderWithItem(100, 20);

        $invoice = app(InvoiceFromOrderService::class)->createFromOrder($order, [], 1);
        $this->assertEquals(100, (float) $invoice->subtotal);
        $this->assertEquals(20, (float) $invoice->discount);
        $this->assertEquals(80, (float) $invoice->total);

        $invoice->update(['status' => 'paid']);
        Payment::create([
            'invoice_id' => $invoice->id,
            'number' => Payment::generateNumber(),
            'method' => 'cash',
            'amount' => 80,
            'paid_at' => now(),
            'branch_id' => $order->branch_id,
        ]);

        $transaction = app(AccountingService::class)->createJournalEntryForInvoice($invoice->fresh());
        $this->assertNotNull($transaction);

        $entries = Entry::where('transaction_id', $transaction->id)->get();
        $totalDebit = $entries->sum('debit');
        $totalCredit = $entries->sum('credit');

        $this->assertEqualsWithDelta(100, $totalDebit, 0.01);
        $this->assertEqualsWithDelta(100, $totalCredit, 0.01);

        $discountEntry = $entries->first(fn ($e) => $e->account->code === '4120');
        $this->assertNotNull($discountEntry);
        $this->assertEquals(20, (float) $discountEntry->debit);

        $revenueEntry = $entries->first(fn ($e) => $e->account->code === '4110');
        $this->assertEquals(100, (float) $revenueEntry->credit);
    }

    #[Test]
    public function it_includes_engine_discount_in_invoice_and_journal(): void
    {
        $discount = Discount::create([
            'name' => 'Employee 20%',
            'code' => 'EMP-20',
            'discount_type' => 'percentage',
            'value' => 20,
            'priority' => 1,
            'is_active' => true,
            'created_by' => 1,
        ]);
        DiscountTarget::create([
            'discount_id' => $discount->id,
            'target_type' => 'employee',
            'target_id' => 5,
        ]);

        $order = $this->createOrderWithItem(100, 0);

        $invoice = app(InvoiceFromOrderService::class)->createFromOrder($order, [
            'employee_id' => 5,
        ], 1);

        $this->assertEquals(20, (float) $invoice->discount);
        $this->assertEquals(80, (float) $invoice->total);

        $invoice->update(['status' => 'paid']);
        Payment::create([
            'invoice_id' => $invoice->id,
            'number' => Payment::generateNumber(),
            'method' => 'cash',
            'amount' => 80,
            'paid_at' => now(),
            'branch_id' => $order->branch_id,
        ]);

        $transaction = app(AccountingService::class)->createJournalEntryForInvoice($invoice->fresh());
        $entries = Entry::where('transaction_id', $transaction->id)->get();

        $this->assertEqualsWithDelta(
            $entries->sum('debit'),
            $entries->sum('credit'),
            0.01
        );
    }

    #[Test]
    public function journal_entry_api_uses_sale_transaction_type(): void
    {
        $order = $this->createOrderWithItem(100, 0);
        $invoice = app(InvoiceFromOrderService::class)->createFromOrder($order, [], 1);
        $invoice->update(['status' => 'paid', 'total' => 100]);
        Payment::create([
            'invoice_id' => $invoice->id,
            'number' => Payment::generateNumber(),
            'method' => 'cash',
            'amount' => 100,
            'paid_at' => now(),
            'branch_id' => $order->branch_id,
        ]);

        app(AccountingService::class)->createJournalEntryForInvoice($invoice->fresh());

        $transaction = Transaction::where('source_id', $order->id)
            ->where('type', 'sale')
            ->first();

        $this->assertNotNull($transaction);
        $this->assertNotNull($order->fresh()->journalEntry());
    }
}
