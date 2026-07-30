<?php

namespace Tests\Feature;

use App\Models\{Account, PaymentMethod};
use App\Services\Accounting\PaymentPlanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CallCenterPaymentPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_card_reference_and_mixed_entity_subledger_are_normalized_safely(): void
    {
        $cash = $this->method('Cash', 'cash');
        $card = $this->method('Card', 'card');
        $customer = $this->method('Customer', 'customer', true);

        $rows = app(PaymentPlanValidator::class)->validate(100, [
            ['payment_method_id' => $cash->id, 'amount' => 20],
            ['payment_method_id' => $card->id, 'amount' => 30, 'reference_number' => 'CARD-1'],
            ['payment_method_id' => $customer->id, 'amount' => 50, 'entity_type' => 'customer', 'entity_id' => 77],
        ]);

        $this->assertSame('CARD-1', $rows[1]['reference_number']);
        $this->assertSame('customer', $rows[2]['subledger_type']);
        $this->assertSame(77, $rows[2]['subledger_id']);
    }

    public function test_under_and_over_payment_are_rejected(): void
    {
        $cash = $this->method('Cash', 'cash');
        foreach ([99, 101] as $amount) {
            try {
                app(PaymentPlanValidator::class)->validate(100, [['payment_method_id' => $cash->id, 'amount' => $amount]]);
                $this->fail('Expected mismatched payment total to fail.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_entity_method_requires_entity_and_direct_method_does_not_invent_subledger(): void
    {
        $cash = $this->method('Cash', 'cash');
        $employee = $this->method('Employee', 'employee', true);

        $direct = app(PaymentPlanValidator::class)->validate(10, [['payment_method_id' => $cash->id, 'amount' => 10]]);
        $this->assertNull($direct[0]['subledger_type']);
        $this->assertNull($direct[0]['subledger_id']);

        $this->expectException(RuntimeException::class);
        app(PaymentPlanValidator::class)->validate(10, [['payment_method_id' => $employee->id, 'amount' => 10]]);
    }

    private function method(string $name, string $type, bool $entity = false): PaymentMethod
    {
        $account = Account::create([
            'name' => "{$name} Account",
            'code' => 'TEST-'.strtoupper($type).'-'.uniqid(),
            'type' => 'asset',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);

        return PaymentMethod::create([
            'name' => $name,
            'type' => $type,
            'account_id' => $account->id,
            'is_active' => true,
            'is_entity' => $entity,
        ]);
    }
}
