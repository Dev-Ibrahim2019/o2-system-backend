<?php

namespace Tests\Feature\Inventory;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Entry;
use App\Models\PurchaseBill;
use App\Models\StockBalance;
use App\Models\StockBatch;
use App\Models\StockDocument;
use App\Models\StockItem;
use App\Models\StockItemUnit;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Voucher;
use App\Models\Warehouse;
use App\Services\Accounting\SupplierAccountingService;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** فاتورة المشتريات الموحّدة: الترحيل = استلام + ذمة + قيد + سند، والعكس يعكس الأربعة. */
class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const BUYER = [
        'inventory.access', 'inventory.warehouses.all', 'inventory.costs.view', 'inventory.documents.reverse',
        'purchases.view', 'purchases.create', 'purchases.post', 'purchases.reverse', 'purchases.pay',
    ];

    private User $buyer;

    private Warehouse $store;

    private Supplier $supplier;

    private StockItem $meat;

    private StockUnit $kg;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountsSeeder::class);
        $this->buyer = $this->user(self::BUYER);
        $this->store = Warehouse::create(['name' => 'المخزن الرئيسي', 'code' => 'WH-MAIN', 'branch_id' => Branch::factory()->create()->id]);
        $this->supplier = Supplier::create(['name' => 'ملاحم الأمين', 'code' => 'SUP-1', 'tax_number' => '562100100', 'payment_terms' => 'net15']);
        $this->kg = $this->unit('كيلوغرام');
        // لحمة: الأساسية غرام، والكيلو 1000
        $this->meat = StockItem::create(['type' => 'raw', 'name' => 'لحمة', 'code' => 'RM-MEAT', 'base_unit_id' => $this->unit('غرام')->id, 'is_stored' => true]);
        StockItemUnit::create(['stock_item_id' => $this->meat->id, 'unit_id' => $this->kg->id, 'factor' => 1000]);
        $this->cash = (int) Account::where('code', '11101')->value('id');
    }

    // ── أدوات ─────────────────────────────────────────────────────────────────

    private function user(array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function unit(string $name): StockUnit
    {
        return StockUnit::firstOrCreate(['name' => $name], ['is_active' => true]);
    }

    private function meatLine(string $kg, string $price, array $extra = []): array
    {
        return ['kind' => 'stock', 'stock_item_id' => $this->meat->id, 'unit_id' => $this->kg->id, 'quantity' => $kg, 'unit_price' => $price, ...$extra];
    }

    private function create(array $lines, array $extra = []): TestResponse
    {
        return $this->actingAs($this->buyer)->postJson('/api/inventory/purchases', [
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->store->id, 'payment_mode' => 'credit',
            'lines' => $lines, ...$extra,
        ]);
    }

    private function postBill(int $id): TestResponse
    {
        return $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$id}/post");
    }

    private function balance(): ?StockBalance
    {
        return StockBalance::where('warehouse_id', $this->store->id)->where('stock_item_id', $this->meat->id)->first();
    }

    /** @return array<string, string> مدين/دائن القيد حسب كود الحساب */
    private function entryLines(int $txnId): array
    {
        return Entry::where('transaction_id', $txnId)->with('account:id,code')->get()
            ->mapWithKeys(fn ($e) => [$e->account->code => (float) $e->debit > 0 ? 'D'.number_format((float) $e->debit, 2, '.', '') : 'C'.number_format((float) $e->credit, 2, '.', '')])
            ->all();
    }

    private function supplierBalance(): float
    {
        return app(SupplierAccountingService::class)->getBalance($this->supplier->fresh());
    }

    // ── الترحيل الكامل ودفع جزئي ─────────────────────────────────────────────

    public function test_posting_creates_receipt_payable_journal_entry_and_partial_payment_voucher(): void
    {
        $transport = (int) Account::where('code', '5210')->value('id');
        $bill = $this->create([
            $this->meatLine('10', '50', ['tax_rate' => 16]),                                                 // 500 + 80 ضريبة
            ['kind' => 'expense', 'description' => 'نقل', 'account_id' => $transport, 'unit_price' => 20],  // مصروف غير مخزني
        ], ['payment_mode' => 'partial', 'paid_on_post' => 200, 'cash_account_id' => $this->cash, 'supplier_invoice_no' => 'S-77'])
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertStringStartsWith('PI-', $bill['bill_number']);
        $this->assertSame('600.0000', $bill['total']);
        // الاستحقاق من شروط المورّد (net15)
        $today = AccountingDay::fromConfig()->of(now());
        $this->assertSame(CarbonImmutable::parse($today)->addDays(15)->toDateString(), $bill['due_date']);
        $this->assertNull($this->balance());   // المسودة لا تمس المخزون

        $res = $this->postBill($bill['id'])->assertOk()->assertJsonPath('data.status', 'partially_paid');
        $this->assertSame('200.0000', $res->json('data.paid_amount'));

        // 1) الاستلام بخدمة الاستلام نفسها: الرصيد ومتوسط التكلفة (بلا الضريبة القابلة للخصم) وآخر سعر
        $grn = StockDocument::findOrFail($res->json('data.stock_document_id'));
        $this->assertSame('posted', $grn->status);
        $this->assertSame($bill['id'], $grn->purchase_bill_id);
        $this->assertSame('10000.000000', $this->balance()->quantity);
        $this->assertSame('0.050000', $this->balance()->avg_cost);
        $this->assertSame('0.050000', $this->meat->fresh()->last_cost);

        // 2+4) القيد: مشتريات 500 + نقل 20 + ضريبة مدخلات 80 / المورّد 600
        $this->assertSame(['5310' => 'D500.00', '5210' => 'D20.00', '1150' => 'D80.00', '2110' => 'C600.00'],
            $this->entryLines((int) $res->json('data.journal_entry_id')));

        // 3) سند الصرف للمدفوع، والذمة المتبقية 400
        $voucher = Voucher::where('purchase_bill_id', $bill['id'])->sole();
        $this->assertSame('200.0000', $voucher->amount);
        $this->assertSame('active', $voucher->status);
        $this->assertSame(['2110' => 'D200.00', '11101' => 'C200.00'], $this->entryLines((int) $voucher->transaction_id));
        $this->assertEqualsWithDelta(400, $this->supplierBalance(), 0.001);

        // تسديد المتبقي من القائمة
        $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$bill['id']}/pay", ['amount' => 401, 'cash_account_id' => $this->cash])
            ->assertStatus(422);
        $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$bill['id']}/pay", ['amount' => 400, 'cash_account_id' => $this->cash])
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertEqualsWithDelta(0, $this->supplierBalance(), 0.001);
        $this->assertSame(2, Voucher::where('purchase_bill_id', $bill['id'])->count());

        // الترحيل مرة ثانية لا يكرّر شيئاً
        $this->postBill($bill['id'])->assertOk();
        $this->assertSame(1, StockDocument::where('purchase_bill_id', $bill['id'])->count());
    }

    // ── الخصم والضريبة في التكلفة ─────────────────────────────────────────────

    public function test_bill_discount_is_distributed_and_lowers_stock_cost(): void
    {
        // 10 كغ × 50 = 500، خصم فاتورة 50 → تكلفة 450 = 0.045 ₪/غ
        $id = $this->create([$this->meatLine('10', '50', ['tax_rate' => 16])], ['discount' => 50])->json('data.id');
        $res = $this->postBill($id)->assertOk();

        $this->assertSame('0.045000', $this->balance()->avg_cost);
        $this->assertSame('450.0000', $res->json('data.items.0.total_before_tax'));
        $this->assertSame('50.0000', $res->json('data.items.0.allocated_discount'));
        $this->assertSame('522.0000', $res->json('data.total'));   // 450 + 72 ضريبة
        $this->assertSame(['5310' => 'D450.00', '1150' => 'D72.00', '2110' => 'C522.00'], $this->entryLines((int) $res->json('data.journal_entry_id')));
    }

    public function test_tax_stays_out_of_cost_unless_supplier_is_not_tax_registered(): void
    {
        $id = $this->create([$this->meatLine('10', '50', ['tax_rate' => 16])], ['tax_recoverable' => false])->json('data.id');
        $res = $this->postBill($id)->assertOk();

        // غير قابلة للخصم: تدخل التكلفة 580 ÷ 10000 غ، ولا ضريبة مدخلات
        $this->assertSame('0.058000', $this->balance()->avg_cost);
        $this->assertSame(['5310' => 'D580.00', '2110' => 'C580.00'], $this->entryLines((int) $res->json('data.journal_entry_id')));
    }

    // ── العكس ─────────────────────────────────────────────────────────────────

    public function test_reverse_is_rejected_when_stock_was_consumed_and_names_the_shortage(): void
    {
        $id = $this->create([$this->meatLine('10', '50')], ['payment_mode' => 'cash', 'cash_account_id' => $this->cash])->json('data.id');
        $this->postBill($id)->assertOk()->assertJsonPath('data.status', 'paid');
        app(StockLedger::class)->post(new StockMovementLine($this->store->id, $this->meat->id, 'sale', '-6500'));
        $transactions = Transaction::count();

        $res = $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$id}/reverse", ['reason' => 'خطأ'])->assertStatus(422);
        $this->assertStringContainsString('لحمة', $res->json('message'));
        $this->assertStringContainsString('ينقص 6500', $res->json('message'));

        // لا شيء تغيّر
        $this->assertSame('paid', PurchaseBill::find($id)->status);
        $this->assertSame('3500.000000', $this->balance()->quantity);
        $this->assertSame($transactions, Transaction::count());
        $this->assertSame('active', Voucher::where('purchase_bill_id', $id)->value('status'));
    }

    public function test_reverse_undoes_receipt_entry_and_payments_together(): void
    {
        $id = $this->create([$this->meatLine('10', '50', ['tax_rate' => 16])], ['payment_mode' => 'partial', 'paid_on_post' => 100, 'cash_account_id' => $this->cash])->json('data.id');
        $this->postBill($id)->assertOk();
        $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$id}/pay", ['amount' => 50, 'cash_account_id' => $this->cash])->assertOk();

        $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$id}/reverse", ['reason' => 'فاتورة خاطئة'])
            ->assertOk()->assertJsonPath('data.status', 'reversed');

        $this->assertSame('0.000000', $this->balance()->quantity);
        $this->assertSame('reversed', StockDocument::where('purchase_bill_id', $id)->value('status'));
        $this->assertSame(['cancelled', 'cancelled'], Voucher::where('purchase_bill_id', $id)->pluck('status')->all());
        $this->assertSame(3, Transaction::where('is_reversal', true)->count());   // الفاتورة + دفعتان
        $this->assertEqualsWithDelta(0, $this->supplierBalance(), 0.001);

        $this->actingAs($this->buyer)->postJson("/api/inventory/purchases/{$id}/reverse", ['reason' => 'x'])->assertStatus(422);
    }

    // ── القواعد ───────────────────────────────────────────────────────────────

    public function test_paid_more_than_total_fails_and_writes_nothing(): void
    {
        $id = $this->create([$this->meatLine('1', '50')], ['payment_mode' => 'partial', 'paid_on_post' => 60, 'cash_account_id' => $this->cash])->json('data.id');

        $this->postBill($id)->assertStatus(422);

        $this->assertSame('draft', PurchaseBill::find($id)->status);
        $this->assertSame(0, StockDocument::count());
        $this->assertSame(0, Transaction::count());
        $this->assertNull($this->balance());
    }

    public function test_expiry_tracked_item_needs_expiry_and_gets_an_automatic_batch(): void
    {
        $this->meat->update(['tracks_expiry' => true]);
        $id = $this->create([$this->meatLine('2', '40')])->json('data.id');
        $this->postBill($id)->assertStatus(422);

        $expiry = now()->addMonth()->toDateString();
        $this->actingAs($this->buyer)->putJson("/api/inventory/purchases/{$id}", [
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->store->id, 'payment_mode' => 'credit',
            'lines' => [$this->meatLine('2', '40', ['expiry_date' => $expiry])],
        ])->assertOk();
        $this->postBill($id)->assertOk();

        $batch = StockBatch::sole();
        $this->assertSame('RM-MEAT1', $batch->code);
        $this->assertSame($expiry, $batch->expiry_date->toDateString());
    }

    public function test_linked_receipt_cannot_be_reversed_alone_and_direct_receipt_needs_permission(): void
    {
        $id = $this->create([$this->meatLine('1', '50')])->json('data.id');
        $grnId = $this->postBill($id)->json('data.stock_document_id');

        $this->actingAs($this->buyer)->postJson("/api/inventory/documents/{$grnId}/reverse", ['reason' => 'x'])->assertStatus(422);

        $keeper = $this->user(['inventory.access', 'inventory.warehouses.all', 'inventory.documents.manage']);
        $this->actingAs($keeper)->postJson('/api/inventory/documents', [
            'type' => 'receipt', 'warehouse_id' => $this->store->id, 'supplier_id' => $this->supplier->id,
            'lines' => [['stock_item_id' => $this->meat->id, 'quantity' => 1, 'unit_cost' => 1]],
        ])->assertForbidden();
    }

    public function test_user_sees_only_purchases_of_warehouses_assigned_to_them(): void
    {
        $this->create([$this->meatLine('1', '50')])->assertCreated();
        $other = Warehouse::create(['name' => 'مخزن آخر', 'code' => 'WH-2', 'branch_id' => Branch::factory()->create()->id]);
        $clerk = $this->user(['inventory.access', 'purchases.view']);
        $other->users()->attach($clerk->id, ['created_at' => now()]);

        $this->assertSame([], $this->actingAs($clerk)->getJson('/api/inventory/purchases')->assertOk()->json('data.list.data'));
        $this->assertSame(1, $this->actingAs($this->buyer)->getJson('/api/inventory/purchases')->json('data.cards.drafts'));
    }
}
