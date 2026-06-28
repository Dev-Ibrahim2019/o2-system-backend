<?php

namespace App\Tests\Unit\Services;

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
            'created_by' => 1,
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
}
