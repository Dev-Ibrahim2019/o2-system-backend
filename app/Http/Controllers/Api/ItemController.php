<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\ItemResource;
use App\Http\Requests\Api\StoreItemRequest;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ItemController extends ApiController
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /items
    // ─────────────────────────────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $items = Item::with(['department', 'branches'])
            ->when(request('department_id'), fn($q, $v) => $q->where('department_id', $v))
            ->when(
                request('branch_id'),
                fn($q, $v) =>
                $q->whereHas('branches', fn($qb) => $qb->where('branch_id', $v))
            )
            ->when(
                request('search'),
                fn($q, $v) =>
                $q->where(
                    fn($qb) =>
                    $qb->where('name', 'like', "%{$v}%")
                        ->orWhere('name_ar', 'like', "%{$v}%")
                        ->orWhere('code', 'like', "%{$v}%")
                )
            )
            ->get();

        return $this->success('Items fetched', ItemResource::collection($items));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /items
    // ينشئ الصنف ويربط الفروع في transaction واحدة
    // ─────────────────────────────────────────────────────────────────────────
    public function store(StoreItemRequest $request): JsonResponse
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            $item = Item::create([
                'department_id' => $data['department_id'],
                'name'          => $data['name'],
                'name_ar'       => $data['name_ar'] ?? null,
                'code'          => $data['code'],
                'image'         => $data['image'] ?? null,
                'unit'          => $data['unit'] ?? null,
                'is_active'     => $data['is_active'] ?? true,
            ]);

            if (!empty($data['branches'])) {
                $syncData = $this->buildSyncData($data['branches']);
                $item->branches()->sync($syncData);
            }

            DB::commit();

            return $this->success(
                'تم إضافة الصنف بنجاح',
                new ItemResource($item->load(['department', 'branches'])),
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل إضافة الصنف: ' . $e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /items/{item}
    // ─────────────────────────────────────────────────────────────────────────
    public function show(Item $item): JsonResponse
    {
        return $this->success(
            'Item fetched',
            new ItemResource($item->load(['department', 'branches']))
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /items/{item}
    // يحدّث بيانات الصنف + يعالج الفروع المرتبطة فقط:
    //   - فروع مُرسَلة   → تُحدَّث أو تُضاف
    //   - فروع مُزالة    → تُحذف من الـ pivot (فروع كانت مرتبطة وأُزيلت من الفورم)
    //   - فروع لم تُرسَل → لا تُمَس (غير مرتبطة أصلاً تبقى كذلك)
    // ─────────────────────────────────────────────────────────────────────────
    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $data = $request->validated();

        DB::beginTransaction();
        try {
            // 1. تحديث بيانات الصنف
            $item->update(collect($data)->except('branches')->toArray());

            // 2. معالجة الفروع إذا أُرسلت في الـ request
            if (array_key_exists('branches', $data)) {
                if (empty($data['branches'])) {
                    // المستخدم أزال جميع الفروع → نحذف كل الروابط
                    $item->branches()->detach();
                } else {
                    $syncData = $this->buildSyncData($data['branches']);

                    // sync: يضيف الجديد، يحدّث الموجود، ويحذف اللي أُزيل من القائمة
                    // الفروع التي لم تكن مرتبطة أصلاً ولم تُرسَل → لا تتأثر
                    $item->branches()->sync($syncData);
                }
            }

            DB::commit();

            return $this->success(
                'تم تحديث الصنف بنجاح',
                new ItemResource($item->fresh()->load(['department', 'branches']))
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('فشل تحديث الصنف: ' . $e->getMessage(), 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /items/{item}
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(Item $item): JsonResponse
    {
        $item->branches()->detach();
        $item->delete();

        return $this->success('تم حذف الصنف بنجاح', []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /items/{item}/usages — في أي فروع يُستخدم هذا الصنف
    // ─────────────────────────────────────────────────────────────────────────
    public function usages(Item $item): JsonResponse
    {
        $item->load(['department', 'branches']);

        return $this->success('Item usages fetched', [
            'item'     => $item->only('id', 'name', 'name_ar', 'unit', 'code'),
            'branches' => $item->branches->map(fn($branch) => [
                'id'        => $branch->id,
                'name'      => $branch->name,
                'price'     => (float) $branch->pivot->price,
                'is_active' => (bool)  $branch->pivot->is_active,
            ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: بناء مصفوفة الـ sync للـ pivot
    // ─────────────────────────────────────────────────────────────────────────
    private function buildSyncData(array $branches): array
    {
        $syncData = [];
        foreach ($branches as $b) {
            $syncData[$b['branch_id']] = [
                'price'     => $b['price'],
                'is_active' => $b['is_active'] ?? true,
            ];
        }
        return $syncData;
    }
}
