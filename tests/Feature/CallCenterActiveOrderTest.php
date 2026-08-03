<?php

namespace Tests\Feature;

use App\Services\CallCenter\CallCenterService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CallCenterActiveOrderTest extends TestCase
{
    #[DataProvider('classificationCases')]
    public function test_real_statuses_are_classified_into_explicit_scopes(
        string $status,
        string $type,
        int $tickets,
        array $expected,
    ): void {
        $this->assertSame($expected, CallCenterService::classifyActiveOrder($status, $type, $tickets));
    }

    public static function classificationCases(): array
    {
        return [
            'draft awaiting payment' => ['pending', 'takeaway', 0, ['operational_active', 'awaiting_payment']],
            'explicit pending payment' => ['pending_payment', 'delivery', 0, ['operational_active', 'awaiting_payment']],
            'paid awaiting kitchen' => ['paid', 'takeaway', 0, ['operational_active']],
            'paid in kitchen' => ['paid', 'takeaway', 1, ['operational_active', 'kitchen_active']],
            'delivery paid' => ['paid', 'delivery', 0, ['operational_active', 'delivery_active']],
            'delivery preparing' => ['in_progress', 'delivery', 1, ['operational_active', 'kitchen_active', 'delivery_active']],
            'ready takeaway' => ['ready', 'takeaway', 1, ['operational_active', 'kitchen_active']],
            'served terminal' => ['served', 'delivery', 1, ['fulfilled_recent']],
            'cancelled terminal' => ['cancelled', 'takeaway', 0, []],
        ];
    }
}
