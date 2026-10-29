<?php

namespace App\Services\Inventory;

use InvalidArgumentException;

/**
 * أثر حركة واحدة على رصيد صنف في مخزن ومتوسط تكلفته المرجّح.
 *
 * منطق خالص بلا قاعدة بيانات (القاعدة 6) — StockLedger يستدعيه بعد قفل سطر الرصيد.
 * كل الأرقام نصوص bcmath بدقة 6 خانات (نفس دقة الأعمدة)، والتكلفة لكل وحدة أساسية بالشيكل.
 */
final class CostCalculator
{
    private const SCALE = UnitConverter::SCALE;

    private const WORK = self::SCALE + 6;

    /**
     * @param  string  $oldQty  الرصيد الحالي (قد يكون سالباً إن سُمح بذلك)
     * @param  string  $oldAvg  متوسط التكلفة الحالي لكل وحدة أساسية
     * @param  string  $quantity  كمية الحركة بإشارة: موجبة = وارد، سالبة = صادر
     * @param  string|null  $unitCost  تكلفة الوحدة: إلزامية للوارد؛ للصادر null = متوسط التكلفة الحالي
     * @return array{quantity: string, avg_cost: string, unit_cost: string, total_cost: string}
     */
    public static function apply(string $oldQty, string $oldAvg, string $quantity, ?string $unitCost): array
    {
        $cmp = bccomp($quantity, '0', self::SCALE);
        if ($cmp === 0) {
            throw new InvalidArgumentException('كمية الحركة لا يمكن أن تكون صفراً.');
        }
        if ($unitCost !== null && bccomp($unitCost, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('تكلفة الوحدة لا يمكن أن تكون سالبة.');
        }

        // الرصيد الجديد = الرصيد الحالي + كمية الحركة (بإشارتها)
        $newQty = bcadd($oldQty, $quantity, self::SCALE);

        if ($cmp > 0) {
            if ($unitCost === null) {
                throw new InvalidArgumentException('تكلفة الوحدة مطلوبة للحركة الواردة.');
            }
            $newAvg = bccomp($oldQty, '0', self::SCALE) <= 0
                // القرار 4: وارد على رصيد صفري أو سالب → متوسط التكلفة الجديد = تكلفة الوارد نفسه.
                ? $unitCost
                // المتوسط المرجّح = (الرصيد الحالي × المتوسط الحالي + كمية الوارد × تكلفته) ÷ (الرصيد الحالي + كمية الوارد)
                // مثال: (10 كغ × 40 + 10 كغ × 50) = 900 ÷ 20 كغ = 45
                : bcdiv(
                    bcadd(bcmul($oldQty, $oldAvg, self::WORK), bcmul($quantity, $unitCost, self::WORK), self::WORK),
                    $newQty,
                    self::WORK,
                );
            $cost = $unitCost;
        } else {
            // الصادر يخرج بمتوسط التكلفة الحالي، ولا يغيّر المتوسط.
            $cost = $unitCost ?? $oldAvg;
            $newAvg = $oldAvg;

            // صادر بتكلفة محددة تختلف عن المتوسط (عكس وارد بتكلفته الأصلية — القرار 8):
            // المتوسط الجديد = (الرصيد الحالي × المتوسط الحالي − الكمية الصادرة × تكلفتها) ÷ الرصيد الجديد
            // يُطبّق فقط إن بقي رصيد موجب وقيمة غير سالبة؛ وإلا يبقى المتوسط كما هو.
            if ($unitCost !== null && bccomp($unitCost, $oldAvg, self::SCALE) !== 0 && bccomp($newQty, '0', self::SCALE) > 0) {
                $newValue = bcadd(bcmul($oldQty, $oldAvg, self::WORK), bcmul($quantity, $unitCost, self::WORK), self::WORK);
                if (bccomp($newValue, '0', self::SCALE) >= 0) {
                    $newAvg = bcdiv($newValue, $newQty, self::WORK);
                }
            }
        }

        return [
            'quantity' => $newQty,
            'avg_cost' => UnitConverter::round($newAvg),
            'unit_cost' => UnitConverter::round($cost),
            // قيمة الحركة = الكمية (بإشارتها) × تكلفة الوحدة
            'total_cost' => UnitConverter::round(bcmul($quantity, $cost, self::WORK)),
        ];
    }
}
