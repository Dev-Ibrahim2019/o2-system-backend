<?php

namespace Tests\Unit\Services;

use App\Models\Discount;
use App\Models\DiscountTarget;
use App\Models\DiscountSetting;
use App\Services\Discount\DiscountEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountEngineServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DiscountEngineService $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DiscountEngineService();

        // Ensure settings exist
        DiscountSetting::set('sales_discounts_account_code', '4120');
        DiscountSetting::set('max_discount_percent', '100');
        DiscountSetting::set('allow_compound_discounts', 'true');
    }

    // ── Helper Methods ──────────────────────────────────────────────

    private function createDiscount(array $overrides = []): Discount
    {
        return Discount::create(array_merge([
            'name' => 'Test Discount',
            'name_ar' => 'خصم تجريبي',
            'code' => 'TEST-' . uniqid(),
            'discount_type' => 'percentage',
            'value' => 10,
            'priority' => 1,
            'is_active' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ], $overrides));
    }

    private function createTarget(Discount $discount, string $type, ?int $targetId = null): DiscountTarget
    {
        return DiscountTarget::create([
            'discount_id' => $discount->id,
            'target_type' => $type,
            'target_id' => $targetId,
        ]);
    }

    // ── Tests ───────────────────────────────────────────────────────

    /** @test */
    public function it_returns_null_when_no_discounts_exist(): void
    {
        $result = $this->engine->getBestDiscount(100);

        $this->assertNull($result);
    }

    /** @test */
    public function it_applies_percentage_discount_to_customer(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 15,
        ]);
        $this->createTarget($discount, 'customer', 5);

        $result = $this->engine->getBestDiscount(100, 1, 5);

        $this->assertNotNull($result);
        $this->assertEquals(15, $result['discount_amount']);
        $this->assertEquals(85, $result['final_price']);
        $this->assertEquals(15, $result['discount_percent']);
    }

    /** @test */
    public function it_applies_fixed_amount_discount(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'fixed_amount',
            'value' => 20,
        ]);
        $this->createTarget($discount, 'all');

        $result = $this->engine->getBestDiscount(100, 1);

        $this->assertNotNull($result);
        $this->assertEquals(20, $result['discount_amount']);
        $this->assertEquals(80, $result['final_price']);
    }

    /** @test */
    public function it_applies_price_override_discount(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'price_override',
            'value' => 50,
        ]);
        $this->createTarget($discount, 'item', 10);

        $result = $this->engine->getBestDiscount(100, 1, null, null, null, null, 10);

        $this->assertNotNull($result);
        $this->assertEquals(50, $result['final_price']);
        $this->assertEquals(50, $result['discount_amount']);
    }

    /** @test */
    public function it_respects_discount_priority(): void
    {
        // High priority discount (lower number = higher priority)
        $highPriority = $this->createDiscount([
            'name' => 'High Priority',
            'code' => 'HIGH-' . uniqid(),
            'value' => 5,
            'priority' => 1,
        ]);
        $this->createTarget($highPriority, 'all');

        // Low priority discount
        $lowPriority = $this->createDiscount([
            'name' => 'Low Priority',
            'code' => 'LOW-' . uniqid(),
            'value' => 20,
            'priority' => 10,
        ]);
        $this->createTarget($lowPriority, 'all');

        $result = $this->engine->getBestDiscount(100);

        $this->assertNotNull($result);
        $this->assertEquals('High Priority', $result['discount']->name);
    }

    /** @test */
    public function it_does_not_apply_expired_discount(): void
    {
        $discount = $this->createDiscount([
            'end_date' => now()->subDay()->toDateString(), // expired yesterday
        ]);
        $this->createTarget($discount, 'all');

        $result = $this->engine->getBestDiscount(100);

        $this->assertNull($result);
    }

    /** @test */
    public function it_does_not_apply_inactive_discount(): void
    {
        $discount = $this->createDiscount([
            'is_active' => false,
        ]);
        $this->createTarget($discount, 'all');

        $result = $this->engine->getBestDiscount(100);

        $this->assertNull($result);
    }

    /** @test */
    public function it_does_not_apply_discount_above_100_percent(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 150, // Invalid: > 100%
        ]);
        $this->createTarget($discount, 'all');

        // The model's calculateDiscount should cap at 100%
        $discountAmount = $discount->calculateDiscount(100);

        $this->assertEquals(100, $discountAmount);
    }

    /** @test */
    public function it_calculates_cart_discounts_correctly(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 10,
        ]);
        $this->createTarget($discount, 'all');

        $items = [
            ['price' => 100, 'quantity' => 2, 'item_id' => 1, 'item_name' => 'Item 1'],
            ['price' => 50, 'quantity' => 1, 'item_id' => 2, 'item_name' => 'Item 2'],
        ];

        $result = $this->engine->calculateCartDiscounts($items);

        $this->assertEquals(250, $result['total_original']);
        $this->assertEquals(25, $result['total_discount']); // 10% of 250
        $this->assertEquals(225, $result['total_final']);
    }

    /** @test */
    public function it_applies_discount_to_specific_employee(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 25,
        ]);
        $this->createTarget($discount, 'employee', 3);

        // Should apply for employee 3
        $result = $this->engine->getBestDiscount(100, 1, null, 3);
        $this->assertNotNull($result);
        $this->assertEquals(25, $result['discount_amount']);

        // Should NOT apply for employee 4
        $result2 = $this->engine->getBestDiscount(100, 1, null, 4);
        $this->assertNull($result2);
    }

    /** @test */
    public function it_applies_discount_to_specific_department(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 15,
        ]);
        $this->createTarget($discount, 'department', 2);

        $result = $this->engine->getBestDiscount(100, 1, null, null, null, 2);

        $this->assertNotNull($result);
        $this->assertEquals(15, $result['discount_amount']);
    }

    /** @test */
    public function it_applies_discount_to_specific_item(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'fixed_amount',
            'value' => 30,
        ]);
        $this->createTarget($discount, 'item', 42);

        $result = $this->engine->getBestDiscount(100, 1, null, null, null, null, 42);

        $this->assertNotNull($result);
        $this->assertEquals(30, $result['discount_amount']);
    }

    /** @test */
    public function it_respects_min_order_amount(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 20,
            'min_order_amount' => 150,
        ]);
        $this->createTarget($discount, 'all');

        // Order amount = 100 * 1 = 100 < 150, should NOT apply
        $result = $this->engine->getBestDiscount(100, 1);
        $this->assertNull($result);

        // Order amount = 200 * 1 = 200 >= 150, should apply
        $result2 = $this->engine->getBestDiscount(200, 1);
        $this->assertNotNull($result2);
    }

    /** @test */
    public function it_respects_max_discount_amount(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 50,
            'max_discount_amount' => 20,
        ]);
        $this->createTarget($discount, 'all');

        // 50% of 100 = 50, but max is 20
        $result = $this->engine->getBestDiscount(100, 1);

        $this->assertNotNull($result);
        $this->assertEquals(20, $result['discount_amount']);
        $this->assertEquals(80, $result['final_price']);
    }

    /** @test */
    public function it_never_returns_negative_price(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'fixed_amount',
            'value' => 150, // More than the price
        ]);
        $this->createTarget($discount, 'all');

        $result = $this->engine->getBestDiscount(100, 1);

        $this->assertNotNull($result);
        $this->assertEquals(0, $result['final_price']);
    }

    /** @test */
    public function it_applies_discount_to_all_quantities_in_cart(): void
    {
        // Percentage discount on all items
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 20,
        ]);
        $this->createTarget($discount, 'all');

        $items = [
            ['price' => 25, 'quantity' => 4, 'item_id' => 1, 'item_name' => 'Same Item x4'],
        ];

        $result = $this->engine->calculateCartDiscounts($items);

        // unit discount: 25 * 20% = 5 per piece
        // total for qty 4: 5 * 4 = 20
        $this->assertEquals(100, $result['total_original']);         // 25 * 4
        $this->assertEquals(20, $result['total_discount']);          // 5 * 4
        $this->assertEquals(80, $result['total_final']);             // 100 - 20
        $this->assertEquals(20, $result['items'][0]['discount_amount']);  // line total
        $this->assertEquals(80, $result['items'][0]['final_total']);
    }

    /** @test */
    public function it_applies_discount_to_same_item_in_multiple_lines(): void
    {
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 10,
        ]);
        $this->createTarget($discount, 'all');

        // Same item added twice as separate lines (e.g., qty 2 + qty 2)
        $items = [
            ['price' => 50, 'quantity' => 2, 'item_id' => 1, 'item_name' => 'Item A (first)'],
            ['price' => 50, 'quantity' => 2, 'item_id' => 1, 'item_name' => 'Item A (second)'],
        ];

        $result = $this->engine->calculateCartDiscounts($items);

        // Each line: 50 * 2 = 100 original, 10% = 10 discount per line
        // Total: 200 original, 20 discount, 180 final
        $this->assertEquals(200, $result['total_original']);
        $this->assertEquals(20, $result['total_discount']);
        $this->assertEquals(180, $result['total_final']);

        // Both lines should have the same discount
        $this->assertEquals(10, $result['items'][0]['discount_amount']);
        $this->assertEquals(10, $result['items'][1]['discount_amount']);
    }

    // ── AND Logic Tests ───────────────────────────────────────────

    /** @test */
    public function it_requires_all_targets_to_match_and(): void
    {
        // خصم لموظف 3 + صنف 42 (AND: كلا الشرطين يجب أن يتحققا)
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 20,
        ]);
        $this->createTarget($discount, 'employee', 3);
        $this->createTarget($discount, 'item', 42);

        // الموظف صحيح والصنف صحيح → يطبق
        $result = $this->engine->getBestDiscount(100, 1, null, 3, null, null, 42);
        $this->assertNotNull($result);
        $this->assertEquals(20, $result['discount_amount']);

        // الموظف صحيح والصنف خطأ → لا يطبق
        $result2 = $this->engine->getBestDiscount(100, 1, null, 3, null, null, 99);
        $this->assertNull($result2);

        // الموظف خطأ والصنف صحيح → لا يطبق
        $result3 = $this->engine->getBestDiscount(100, 1, null, 5, null, null, 42);
        $this->assertNull($result3);

        // الموظف خطأ والصنف خطأ → لا يطبق
        $result4 = $this->engine->getBestDiscount(100, 1, null, 5, null, null, 99);
        $this->assertNull($result4);
    }

    /** @test */
    public function it_requires_customer_and_department_both_to_match_and(): void
    {
        // خصم لعميل 5 + قسم 2 (AND)
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 15,
        ]);
        $this->createTarget($discount, 'customer', 5);
        $this->createTarget($discount, 'department', 2);

        // العميل صحيح والقسم صحيح → يطبق
        $result = $this->engine->getBestDiscount(100, 1, 5, null, null, 2);
        $this->assertNotNull($result);
        $this->assertEquals(15, $result['discount_amount']);

        // العميل صحيح والقسم خطأ → لا يطبق
        $result2 = $this->engine->getBestDiscount(100, 1, 5, null, null, 99);
        $this->assertNull($result2);

        // العميل خطأ والقسم صحيح → لا يطبق
        $result3 = $this->engine->getBestDiscount(100, 1, 10, null, null, 2);
        $this->assertNull($result3);
    }

    /** @test */
    public function it_requires_employee_and_department_and_item_all_to_match_and(): void
    {
        // خصم بثلاثة شروط: موظف 3 + قسم 2 + صنف 42 (AND للكل)
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 25,
        ]);
        $this->createTarget($discount, 'employee', 3);
        $this->createTarget($discount, 'department', 2);
        $this->createTarget($discount, 'item', 42);

        // الكل صحيح → يطبق
        $result = $this->engine->getBestDiscount(100, 1, null, 3, null, 2, 42);
        $this->assertNotNull($result);
        $this->assertEquals(25, $result['discount_amount']);

        // الموظف والصنف صحيحين، القسم خطأ → لا يطبق
        $result2 = $this->engine->getBestDiscount(100, 1, null, 3, null, 99, 42);
        $this->assertNull($result2);

        // الموظف والقسم صحيحين، الصنف خطأ → لا يطبق
        $result3 = $this->engine->getBestDiscount(100, 1, null, 3, null, 2, 99);
        $this->assertNull($result3);

        // القسم والصنف صحيحين، الموظف خطأ → لا يطبق
        $result4 = $this->engine->getBestDiscount(100, 1, null, 5, null, 2, 42);
        $this->assertNull($result4);
    }

    /** @test */
    public function it_does_not_require_supplier_when_not_in_targets(): void
    {
        // خصم على صنف 42 فقط (بدون شرط مورد)
        $discount = $this->createDiscount([
            'discount_type' => 'fixed_amount',
            'value' => 10,
        ]);
        $this->createTarget($discount, 'item', 42);

        // الصنف صحيح حتى مع وجود مورد → يطبق (المورد غير مطلوب)
        $result = $this->engine->getBestDiscount(100, 1, null, null, 8, null, 42);
        $this->assertNotNull($result);
        $this->assertEquals(10, $result['discount_amount']);
    }

    /** @test */
    public function it_does_not_require_customer_when_not_in_targets(): void
    {
        // خصم على موظف 3 فقط (بدون شرط عميل)
        $discount = $this->createDiscount([
            'discount_type' => 'percentage',
            'value' => 30,
        ]);
        $this->createTarget($discount, 'employee', 3);

        // الموظف صحيح حتى مع وجود عميل → يطبق (العميل غير مطلوب)
        $result = $this->engine->getBestDiscount(100, 1, 10, 3);
        $this->assertNotNull($result);
        $this->assertEquals(30, $result['discount_amount']);
    }

    /** @test */
    public function it_requires_employee_and_two_items_to_match_and(): void
    {
        // خصم لموظف 3 + صنف 42 (AND)
        // ثم نضيف نفس الخصم مرتين لكن بصنفين مختلفين في قائمة الأصناف
        // هذا يختبر أن target واحد من نوع item لا يمرر الخصم إذا الموظف غير صحيح
        $discount = $this->createDiscount([
            'discount_type' => 'fixed_amount',
            'value' => 5,
        ]);
        $this->createTarget($discount, 'employee', 3);
        $this->createTarget($discount, 'item', 42);

        // موظف 3 + صنف 42 → يطبق
        $result = $this->engine->getBestDiscount(50, 1, null, 3, null, null, 42);
        $this->assertNotNull($result);
        $this->assertEquals(5, $result['discount_amount']);

        // صنف 42 مع موظف آخر → لا يطبق (الموظف لا يطابق)
        $result2 = $this->engine->getBestDiscount(50, 1, null, 7, null, null, 42);
        $this->assertNull($result2);
    }
}
