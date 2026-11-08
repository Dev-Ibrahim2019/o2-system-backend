<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\StockItem;
use App\Models\StockUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\AccountingDay;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockMovementLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** "الحركات المخزنية": قائمة موحدة بالمستندات المرحّلة وأرصدة أول المدة، بالفلاتر وحدود المخازن. */
class StockJournalTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = [
        'inventory.access', 'inventory.warehouses.all', 'inventory.costs.view',
        'inventory.documents.manage', 'inventory.documents.post', 'inventory.documents.reverse',
    ];

    private User $admin;

    private Warehouse $store;

    private Warehouse $kitchen;

    private StockItem $rice;

    private StockItem $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user(self::ADMIN);
        $this->store = Warehouse::create(['name' => 'المخزن الرئيسي', 'code' => 'WH-MAIN', 'branch_id' => Branch::factory()->create()->id]);
        $this->kitchen = Warehouse::create(['name' => 'المطبخ', 'code' => 'WH-KIT', 'branch_id' => Branch::factory()->create()->id]);
        $kg = StockUnit::firstOrCreate(['name' => 'كيلوغرام'], ['is_active' => true]);
        $this->rice = StockItem::create(['type' => 'raw', 'name' => 'أرز', 'code' => 'RICE', 'base_unit_id' => $kg->id, 'is_stored' => true]);
        $this->oil = StockItem::create(['type' => 'raw', 'name' => 'زيت', 'code' => 'OIL', 'base_unit_id' => $kg->id, 'is_stored' => true]);
    }

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

    /** رصيد أول مدة بسطرين دفعة واحدة (يظهر سطراً واحداً في الحركات). */
    private function opening(): void
    {
        app(StockLedger::class)->postMany([
            new StockMovementLine($this->store->id, $this->rice->id, 'opening', '100', unitCost: '2'),
            new StockMovementLine($this->store->id, $this->oil->id, 'opening', '50', unitCost: '4'),
        ], $this->admin);
    }

    private function transfer(array $extra = [], bool $post = true): int
    {
        $id = $this->actingAs($this->admin)->postJson('/api/inventory/documents', [
            'type' => 'transfer', 'warehouse_id' => $this->store->id, 'to_warehouse_id' => $this->kitchen->id,
            'lines' => [['stock_item_id' => $this->rice->id, 'quantity' => 10]], ...$extra,
        ])->assertCreated()->json('data.id');
        if ($post) {
            $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/post")->assertOk();
        }

        return $id;
    }

    private function journal(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin)->getJson('/api/inventory/journal?'.http_build_query($query))->assertOk()->json('data');
    }

    public function test_lists_posted_documents_and_grouped_opening_but_not_drafts(): void
    {
        $this->opening();
        $posted = $this->transfer(['reference_no' => 'DEPT-7', 'notes' => 'صرف صباحي']);
        $this->transfer(post: false);

        $data = $this->journal();
        $this->assertSame(2, $data['summary']['count']);
        [$opening, $transfer] = $data['data'];

        $this->assertSame('opening', $opening['kind']);
        $this->assertStringStartsWith('OPN-', $opening['number']);
        $this->assertSame('WH-MAIN', $opening['account_code']);
        $this->assertSame('400.000000', $opening['amount']); // 100×2 + 50×4

        $this->assertSame($posted, $transfer['id']);
        $this->assertSame('transfer', $transfer['kind']);
        $this->assertSame('صرفية أصناف', $transfer['kind_label']);
        $this->assertSame('DEPT-7', $transfer['reference_no']);
        // الحساب في الصرفية = المخزن المحول له
        $this->assertSame('WH-KIT', $transfer['account_code']);
        $this->assertSame('المطبخ', $transfer['account_name']);
        $this->assertSame('صرف صباحي', $transfer['description']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $transfer['time']);
        $this->assertSame(AccountingDay::fromConfig()->of(now()), $transfer['day']);
    }

    public function test_filters_by_type_date_and_search(): void
    {
        $this->opening();
        $this->transfer(['reference_no' => 'DEPT-7']);
        $today = AccountingDay::fromConfig()->of(now());

        $this->assertSame(['transfer'], array_column($this->journal(['type' => 'transfer'])['data'], 'kind'));
        $this->assertSame(['opening'], array_column($this->journal(['type' => 'opening'])['data'], 'kind'));
        $this->assertCount(2, $this->journal(['from' => $today, 'to' => $today])['data']);
        $this->assertCount(0, $this->journal(['to' => now()->subDays(5)->toDateString()])['data']);
        $this->assertSame(['transfer'], array_column($this->journal(['search' => 'DEPT'])['data'], 'kind'));
    }

    public function test_reversed_document_is_labelled_and_users_see_only_their_warehouses(): void
    {
        $this->opening();
        $id = $this->transfer();
        $this->actingAs($this->admin)->postJson("/api/inventory/documents/{$id}/reverse", ['reason' => 'خطأ'])->assertOk();
        $this->assertSame('صرفية أصناف (معكوس)', collect($this->journal()['data'])->firstWhere('id', $id)['kind_label']);

        // أمين المطبخ يرى الصرفية الواردة لمطبخه فقط (لا رصيد أول المدة في المخزن الرئيسي)، وبلا تكاليف
        $keeper = $this->user(['inventory.access']);
        $this->kitchen->users()->attach($keeper->id, ['created_at' => now()]);
        $rows = $this->journal([], $keeper);
        $this->assertSame(['transfer'], array_column($rows['data'], 'kind'));
        $this->assertNull($rows['data'][0]['amount']);
        $this->assertNull($rows['summary']['amount']);

        $stranger = $this->user(['inventory.access']);
        $this->assertSame(0, $this->journal([], $stranger)['summary']['count']);
    }

    public function test_opening_detail_returns_the_lines_entered_together(): void
    {
        $this->opening();
        $row = $this->journal(['type' => 'opening'])['data'][0];

        $detail = $this->actingAs($this->admin)->getJson("/api/inventory/journal/opening/{$row['id']}")->assertOk()->json('data');
        $this->assertSame($row['number'], $detail['number']);
        $this->assertSame(['RICE', 'OIL'], array_column($detail['lines'], 'code'));
        $this->assertSame('200.000000', $detail['lines'][0]['total_cost']);

        $stranger = $this->user(['inventory.access']);
        $this->actingAs($stranger)->getJson("/api/inventory/journal/opening/{$row['id']}")->assertForbidden();
    }
}
