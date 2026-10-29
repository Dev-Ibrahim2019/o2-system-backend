<?php

namespace App\Services\Inventory;

/**
 * قواعد شجرة تصنيفات المواد الخام.
 *
 * منطق خالص: يأخذ دالة تُرجع أب أي تصنيف، فيُختبر دون قاعدة بيانات.
 */
final class CategoryTree
{
    /** حدّ أمان ضد بيانات تالفة تحتوي حلقة أصلاً — لا يُتوقع عمق حقيقي قريب منه. */
    private const MAX_DEPTH = 100;

    /**
     * هل يؤدي جعل $newParentId أباً لـ $categoryId إلى حلقة؟
     * نصعد من الأب الجديد نحو الجذر؛ إن مررنا بالتصنيف نفسه فالأب الجديد أحد فروعه.
     *
     * @param  callable(int): ?int  $parentOf
     */
    public static function wouldCreateCycle(int $categoryId, ?int $newParentId, callable $parentOf): bool
    {
        $current = $newParentId;
        $steps = 0;

        while ($current !== null) {
            if ($current === $categoryId || ++$steps > self::MAX_DEPTH) {
                return true;
            }
            $current = $parentOf($current);
        }

        return false;
    }
}
