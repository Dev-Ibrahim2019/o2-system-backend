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
            // served/DELIVERED ما توصل هالدالة إلا لو الطلب لسا مو مدفوع بالكامل (وإلا كانت
            // determineLifecycle حسمته closed قبل هيك) — فمنطقيًا لازم تنحسب "بانتظار الدفع"
            // بدل ما تختفي من الطلبات النشطة بس لأنه status="served".
            'served but unpaid stays awaiting payment' => ['served', 'delivery', 1, ['operational_active', 'awaiting_payment']],
            'cancelled terminal' => ['cancelled', 'takeaway', 0, []],
        ];
    }

    #[DataProvider('lifecycleCases')]
    public function test_lifecycle_is_determined_by_status_and_payment_together(
        string $status,
        ?string $invoiceStatus,
        string $expected,
    ): void {
        $this->assertSame($expected, CallCenterService::determineLifecycle($status, $invoiceStatus));
    }

    public static function lifecycleCases(): array
    {
        return [
            // الجدول المطلوب صراحة: الدفع وحده لا يغلق الطلب أبدًا
            'new unpaid -> active' => ['pending', null, 'active'],
            'confirmed unpaid -> active' => ['confirmed', null, 'active'],
            'preparing unpaid -> active' => ['in_progress', null, 'active'],
            'preparing paid -> active (paid ≠ closed)' => ['in_progress', 'paid', 'active'],
            'ready paid -> active' => ['ready', 'paid', 'active'],
            'out for delivery paid -> active' => ['OUT_FOR_DELIVERY', 'paid', 'active'],
            'delivered unpaid -> active (not closed just because delivered)' => ['DELIVERED', null, 'active'],
            'delivered partially paid -> active' => ['DELIVERED', 'partial', 'active'],
            'served unpaid -> active' => ['served', null, 'active'],
            'served paid -> closed' => ['served', 'paid', 'closed'],
            'delivered + fully paid -> closed' => ['DELIVERED', 'paid', 'closed'],
            'cancelled regardless of payment -> closed' => ['cancelled', null, 'closed'],
            'cancelled even if somehow paid -> closed' => ['cancelled', 'paid', 'closed'],
        ];
    }

    #[DataProvider('paymentStatusCases')]
    public function test_payment_status_is_derived_only_from_invoice_status(?string $invoiceStatus, string $expected): void
    {
        $this->assertSame($expected, CallCenterService::derivePaymentStatus($invoiceStatus));
    }

    public static function paymentStatusCases(): array
    {
        return [
            'no invoice yet' => [null, 'unpaid'],
            'invoice paid' => ['paid', 'paid'],
            'invoice partial' => ['partial', 'pending'],
            'invoice awaiting approval' => ['awaiting_approval', 'unpaid'],
            'invoice draft' => ['draft', 'unpaid'],
            'invoice cancelled' => ['cancelled', 'unpaid'],
        ];
    }
}
