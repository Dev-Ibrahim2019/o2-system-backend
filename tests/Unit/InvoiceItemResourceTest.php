<?php

namespace Tests\Unit;

use App\Http\Resources\InvoiceItemResource;
use App\Models\Discount;
use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Tests\TestCase;

class InvoiceItemResourceTest extends TestCase
{
    public function test_it_serializes_loaded_discount_relation_without_conflicting_discount_column(): void
    {
        $item = new InvoiceItem([
            'item_name' => 'Test Item',
            'quantity' => 1,
            'price' => 10,
            'total' => 10,
            'discount_amount' => 2,
            'discount_percent' => 20,
            'discount' => 0,
        ]);

        $item->setRelation('discount', new Discount([
            'id' => 99,
            'name' => 'Summer Deal',
            'name_ar' => 'عرض صيفي',
            'code' => 'SUMMER',
            'discount_type' => 'percentage',
            'value' => 20,
        ]));

        $this->app->instance('request', new Request());

        $resource = new InvoiceItemResource($item);
        $payload = $resource->resolve();

        $this->assertSame('Summer Deal', $payload['discount']['name']);
        $this->assertSame('SUMMER', $payload['discount']['code']);
    }
}
