<?php

namespace App\Services\CallCenter;

use App\Models\Order;
use App\Models\OrderSlot;
use Illuminate\Support\Facades\DB;

/**
 * حجز وتحرير "خانات" الطلبات النشطة بالكول سنتر (راجع migration create_order_slots_table).
 *
 * قاعدة الاحتفاظ بالخانة: الطلب بيمسك خانته من لحظة الإنشاء لحد ما يتسكّر (مدفوع + منفّذ، F7) أو
 * يتلغى — مش عند الدفع، لأنه الموظف لازم يضل شايف "جاهز للإغلاق" بمكانه. بعد الإغلاق بتتحرر الخانة
 * وبتصير متابعة الطلب من شاشة متابعة التيك أواي.
 *
 * كل شي هون idempotent: sync() آمن يتنادى بأي وقت (observer على الطلب + أمر reconcile
 * الدوري). التسلسل بين طلبين بنفس اللحظة مضمون بقفل صف الفرع (SELECT ... FOR UPDATE) — بدل
 * SKIP LOCKED على صفوف خانات جاهزة، لأن الجدول عندنا بيخزّن الخانات المشغولة بس، فتغيير السعة
 * ما بيحتاج ولا صف إضافي.
 */
class OrderSlotService
{
    /** حالات الطلب اللي بتحرّر الخانة — أي حالة غيرها (بانتظار دفع/مجدول/بالمطبخ/مع سائق...) بتمسكها */
    public const TERMINAL_STATUSES = ['closed', 'cancelled', 'canceled', 'CANCELLED'];

    public function shouldHold(Order $order): bool
    {
        if ($order->source !== 'call_center' || ! $order->branch_id) {
            return false;
        }

        return ! in_array($order->status, self::TERMINAL_STATUSES, true);
    }

    /** يوفّق حالة الخانة مع حالة الطلب الحالية: يحجز لو لازم، يحرّر لو خلص دوره. */
    public function sync(Order $order): ?OrderSlot
    {
        $active = OrderSlot::query()->where('order_id', $order->id)->whereNull('released_at')->first();

        if ($this->shouldHold($order)) {
            return $active ?? $this->reserve($order);
        }

        if ($active) {
            $this->release($active, $this->releaseReason($order));
        }

        return null;
    }

    /**
     * يحجز أصغر خانة فاضية (مو مشغولة ومو بفترة الـcooldown) ضمن السعة. null = الفرع ممتلئ، والطلب
     * بيروح لـ"الطابور" وبياخد خانة أول ما تفرغ وحدة (release أو reconcile).
     */
    public function reserve(Order $order): ?OrderSlot
    {
        return DB::transaction(function () use ($order) {
            $capacityValue = DB::table('branches')->where('id', $order->branch_id)->lockForUpdate()->value('order_slot_capacity');
            $capacity = (int) ($capacityValue ?? config('call-center.slots.default_capacity', 200));

            $existing = OrderSlot::query()->where('order_id', $order->id)->whereNull('released_at')->first();
            if ($existing) {
                return $existing;
            }

            $taken = OrderSlot::query()
                ->where('branch_id', $order->branch_id)
                ->where(function ($q) {
                    $q->whereNull('released_at')
                        ->orWhere('released_at', '>', now()->subSeconds($this->cooldownSeconds()));
                })
                ->pluck('slot_number')
                ->flip();

            for ($n = 1; $n <= $capacity; $n++) {
                if (! $taken->has($n)) {
                    return OrderSlot::create([
                        'branch_id' => $order->branch_id,
                        'order_id' => $order->id,
                        'slot_number' => $n,
                        'assigned_at' => now(),
                        'active_slot' => $n,
                        'active_order_id' => $order->id,
                    ]);
                }
            }

            return null;
        });
    }

    /**
     * طلب جديد انعمل بالضغط على خانة فاضية محددة: بياخد هالخانة بدل أصغر خانة فاضية. بيتنادى بس لحظة
     * الإنشاء (مش لنقل طلب قائم — الطلب ما بيتحرك من خانته). خانة بفترة الـcooldown مسموحة هون لأنه
     * الموظف اختارها بنفسه. لو الخانة انحجزت بنفس اللحظة لطلب تاني أو فوق السعة، الطلب بيضل بخانته
     * التلقائية. بيرجّع رقم الخانة النهائي (أو null لو الطلب بالطابور).
     */
    public function claim(Order $order, int $slotNumber): ?int
    {
        if (! $this->shouldHold($order)) {
            return null;
        }

        return DB::transaction(function () use ($order, $slotNumber) {
            $capacityValue = DB::table('branches')->where('id', $order->branch_id)->lockForUpdate()->value('order_slot_capacity');
            $capacity = (int) ($capacityValue ?? config('call-center.slots.default_capacity', 200));
            $current = OrderSlot::query()->where('order_id', $order->id)->whereNull('released_at')->first();

            if ($current && (int) $current->slot_number === $slotNumber) {
                return $slotNumber;
            }

            $taken = OrderSlot::query()
                ->where('branch_id', $order->branch_id)
                ->whereNull('released_at')
                ->where('slot_number', $slotNumber)
                ->exists();
            if ($slotNumber < 1 || $slotNumber > $capacity || $taken) {
                return $current ? (int) $current->slot_number : null;
            }

            if ($current) {
                $current->update(['slot_number' => $slotNumber, 'active_slot' => $slotNumber]);
            } else {
                OrderSlot::create([
                    'branch_id' => $order->branch_id,
                    'order_id' => $order->id,
                    'slot_number' => $slotNumber,
                    'assigned_at' => now(),
                    'active_slot' => $slotNumber,
                    'active_order_id' => $order->id,
                ]);
            }

            return $slotNumber;
        });
    }

    public function release(OrderSlot $slot, string $reason): void
    {
        $slot->update([
            'released_at' => now(),
            'release_reason' => $reason,
            'active_slot' => null,
            'active_order_id' => null,
        ]);

        $this->fillQueue((int) $slot->branch_id);
    }

    /** يعطي الخانات الفاضية لأقدم الطلبات المنتظرة بالطابور (FIFO) لحد ما الفرع يمتلي. */
    public function fillQueue(int $branchId): void
    {
        foreach ($this->queuedOrders($branchId, 50) as $order) {
            if (! $this->reserve($order)) {
                break;
            }
        }
    }

    /** طلبات لازم تمسك خانة (مفتوحة) بس ما إلها خانة لأنه الفرع كان ممتلئ — أقدمها أول. */
    public function queuedOrders(int $branchId, ?int $limit = null)
    {
        return Order::query()->withoutGlobalScopes()
            ->where('source', 'call_center')
            ->where('branch_id', $branchId)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->whereNotIn('id', OrderSlot::query()->whereNull('released_at')->select('order_id'))
            ->orderBy('id')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->filter(fn (Order $order) => $this->shouldHold($order))
            ->values();
    }

    public function capacity(int $branchId): int
    {
        $value = DB::table('branches')->where('id', $branchId)->value('order_slot_capacity');

        return (int) ($value ?? config('call-center.slots.default_capacity', 200));
    }

    /**
     * تصغير السعة مسموح: الطلبات اللي بخانات أعلى من السعة الجديدة بتضل بخاناتها، والخانات الزايدة
     * بتختفي لحالها لما تفرغ (reserve ما بيعطي رقم فوق السعة). زيادة السعة بتفرّغ الطابور فورًا.
     */
    public function setCapacity(int $branchId, int $capacity): void
    {
        DB::table('branches')->where('id', $branchId)->update(['order_slot_capacity' => $capacity]);
        $this->fillQueue($branchId);
    }

    /** الخانات المشغولة حاليًا للفرع، مرتبة بالرقم. */
    public function activeSlots(int $branchId)
    {
        return OrderSlot::query()
            ->where('branch_id', $branchId)
            ->whereNull('released_at')
            ->orderBy('slot_number')
            ->get();
    }

    /**
     * خانات فرغت للتو وما زالت بفترة الـcooldown، مع سبب التحرير (paid/cancelled/closed) ووقته —
     * الواجهة بتستخدمها لتلاشي "مدفوع" أو وميض "ملغي" وبعدين بتعرضها كخانة فاضية.
     */
    public function coolingSlots(int $branchId): array
    {
        return OrderSlot::query()
            ->where('branch_id', $branchId)
            ->whereNotNull('released_at')
            ->where('released_at', '>', now()->subSeconds($this->cooldownSeconds()))
            ->orderByDesc('released_at')
            ->get(['slot_number', 'release_reason', 'released_at'])
            ->unique('slot_number')
            ->map(fn (OrderSlot $slot) => [
                'slot_number' => (int) $slot->slot_number,
                'reason' => $slot->release_reason,
                'released_at' => $slot->released_at,
            ])
            ->values()
            ->all();
    }

    /**
     * شبكة أمان للتحديثات اللي بتتخطى الـobservers (Query Builder ->update، سكربتات، بيانات قديمة):
     * يحرّر الخانات اللي طلبها خلص دوره ويحجز للمنتظرين. بيرجّع عدد التغييرات.
     */
    public function reconcile(): array
    {
        $released = 0;
        $reserved = 0;

        OrderSlot::query()->whereNull('released_at')->with('order')->chunkById(200, function ($slots) use (&$released) {
            foreach ($slots as $slot) {
                $order = $slot->order;
                if (! $order || $order->trashed() || ! $this->shouldHold($order)) {
                    $this->release($slot, $order ? $this->releaseReason($order) : 'missing');
                    $released++;
                }
            }
        });

        $branchIds = Order::query()->withoutGlobalScopes()
            ->where('source', 'call_center')
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->whereNotNull('branch_id')
            ->distinct()
            ->pluck('branch_id');

        foreach ($branchIds as $branchId) {
            foreach ($this->queuedOrders((int) $branchId) as $order) {
                if (! $this->reserve($order)) {
                    break;
                }
                $reserved++;
            }
        }

        return ['released' => $released, 'reserved' => $reserved];
    }

    private function releaseReason(Order $order): string
    {
        return match (true) {
            in_array($order->status, ['cancelled', 'canceled', 'CANCELLED'], true) => 'cancelled',
            default => 'closed',
        };
    }

    private function cooldownSeconds(): int
    {
        return max(0, (int) config('call-center.slots.reuse_cooldown_seconds', 60));
    }
}
