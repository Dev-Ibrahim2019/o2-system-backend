<?php
// app/Http/Controllers/Api/ItemController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Item;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ItemController extends Controller
{
    // ─── List (فلترة صارمة حسب الفرع — لشاشة POS والإدارة) ──
    public function index(Request $request): JsonResponse
    {
        $authUser = auth()->user();
        $branchId = $request->branch_id ?? $authUser?->branch_id;

        $isSuperAdmin = $authUser?->hasRole('super-admin') ?? true;
        $hasNoBranch = ! $authUser?->branch_id;

        $query = Item::with(['department'])
            ->when($request->department_id, fn($q, $id) => $q->where('department_id', $id))
            ->when($request->search, function ($q, $s) {
                $q->where(fn($q) =>
                    $q->where('name', 'like', "%{$s}%")
                      ->orWhere('name_ar', 'like', "%{$s}%")
                      ->orWhere('code', 'like', "%{$s}%")
                );
            });

        $filteredToBranch = false;

        if (! $isSuperAdmin && ! $hasNoBranch && $branchId) {
            $query->whereHas('branches', function ($q) use ($branchId) {
                $q->where('branch_item.branch_id', $branchId)
                  ->where('branch_item.is_active', true);
            });

            $query->with(['branches' => function ($q) use ($branchId) {
                $q->where('branch_item.branch_id', $branchId)
                  ->where('branch_item.is_active', true)
                  ->withPivot(['price', 'is_active']);
            }]);
            $filteredToBranch = true;
        } elseif ($branchId && $request->branch_id) {
            $query->whereHas('branches', fn($q) => $q->where('branch_item.branch_id', $branchId))
                  ->with(['branches' => fn($q) => $q->where('branch_item.branch_id', $branchId)
                      ->withPivot(['price', 'is_active'])]);
            $filteredToBranch = true;
        } else {
            // كتالوج الإدارة بدون سياق فرع محدد (سوبر أدمن بدون branch_id) —
            // نحمّل كل أسعار الفروع عشان نعرض سعر تمثيلي بدل 0 دايماً.
            $query->with(['branches' => fn($q) => $q->withPivot(['price', 'is_active'])]);
        }

        $items = $query->orderBy('code')->get();

        $data = $items->map(function ($item) use ($filteredToBranch) {
            $result = [
                'id'            => $item->id,
                'name'          => $item->name,
                'name_ar'       => $item->name_ar ?? $item->name,
                'code'          => $item->code,
                'image'         => $item->image,
                'image_url'     => $item->image_url,
                'unit'          => $item->unit,
                'department_id' => $item->department_id,
                'department'    => $item->department ? [
                    'id'      => $item->department->id,
                    'name'    => $item->department->name,
                    'name_ar' => $item->department->nameAr ?? $item->department->name,
                    'icon'    => $item->department->icon ?? '🍽️',
                    'color'   => $item->department->color ?? '#ef4444',
                ] : null,
                'is_active'     => $item->is_active,
            ];

            if ($filteredToBranch && $item->branches->isNotEmpty()) {
                $pivot = $item->branches->first()->pivot;
                $result['price'] = (float) ($pivot->price ?? 0);
                $result['is_available'] = (bool) ($pivot->is_active ?? true);
            } elseif ($item->branches->isNotEmpty()) {
                $mainBranch = $item->branches->firstWhere('isMainBranch', true) ?? $item->branches->first();
                $result['price'] = (float) ($mainBranch->pivot->price ?? 0);
                $result['is_available'] = (bool) ($mainBranch->pivot->is_active ?? true);
            } else {
                $result['price'] = 0;
                $result['is_available'] = true;
            }

            // مطلوبة لشاشة تعديل الصنف عشان تعرض الفروع المرتبطة فعلياً (checkboxes + أسعار)
            $result['branches'] = $item->branches->map(function ($branch) {
                $isActive = (bool) ($branch->pivot->is_active ?? true);
                return [
                    'id'          => $branch->id,
                    'name'        => $branch->name,
                    'price'       => (float) ($branch->pivot->price ?? 0),
                    'is_active'   => $isActive,
                    'is_availble' => $isActive,
                ];
            })->values()->all();

            return $result;
        });

        return response()->json(['data' => $data]);
    }

    // ─── Store ───────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'name'          => 'required|string|max:255',
            'name_ar'       => 'required|string|max:255',
            'code'          => 'nullable|string|max:30|unique:items,code',
            'image'         => 'nullable|file|mimetypes:image/jpeg,image/png|max:2048',
            'unit'          => 'nullable|string|max:50',
            'is_active'     => 'boolean',
            'branches'      => 'sometimes|array',
            'branches.*.id' => 'required_with:branches|integer|exists:branches,id',
            'branches.*.price' => 'nullable|numeric|min:0',
            'branches.*.is_active' => 'sometimes|boolean',
            'branches.*.is_availble' => 'sometimes|boolean',
        ]);

        if (empty($data['code'])) {
            $data['code'] = $this->generateCode($data['department_id']);
        }

        if ($request->hasFile('image')) {
            $data['image'] = $this->storeImage($request);
        }

        $item = DB::transaction(function () use ($data) {
            $item = Item::create(Arr::except($data, ['branches']));

            if (array_key_exists('branches', $data)) {
                $item->branches()->sync($this->branchSyncData($data['branches']));
            }

            return $item;
        });

        return response()->json(['data' => $item->load(['department', 'branches'])], 201);
    }

    // ─── Show ─────────────────────────────────────────────────────────────────
    public function show(Item $item): JsonResponse
    {
        $authUser = auth()->user();
        $branchId = $authUser?->branch_id;

        // جلب جميع الفروع المرتبطة بالصنف مع بيانات pivot
        $item->load(['department', 'branches' => function ($q) {
            $q->withPivot(['price', 'is_active']);
        }]);

        // تحويل بيانات الفروع مع الحقلين للتوافق
        $branchesData = $item->branches->map(function ($branch) {
            $isActive = (bool) ($branch->pivot->is_active ?? true);
            return [
                'id'          => $branch->id,
                'name'        => $branch->name,
                'code'        => $branch->code,
                'price'       => (float) ($branch->pivot->price ?? 0),
                'is_active'   => $isActive,
                'is_availble' => $isActive,
            ];
        })->values()->all();

        // السعر العام للصنف: من الفرع الخاص بالمستخدم أو أول فرع
        $generalPrice = $branchId
            ? (float) ($item->branches->firstWhere('id', $branchId)?->pivot->price ?? $item->branches->first()?->pivot->price ?? 0)
            : (float) ($item->branches->first()?->pivot->price ?? 0);

        $data = [
            'id'            => $item->id,
            'name'          => $item->name,
            'name_ar'       => $item->name_ar ?? $item->name,
            'code'          => $item->code,
            'image'         => $item->image,
            'image_url'     => $item->image_url,
            'unit'          => $item->unit,
            'is_active'     => $item->is_active,
            'department_id' => $item->department_id,
            'department'    => $item->department ? [
                'id'      => $item->department->id,
                'name'    => $item->department->name,
                'name_ar' => $item->department->nameAr ?? $item->department->name,
                'icon'    => $item->department->icon ?? '🍽️',
                'color'   => $item->department->color ?? '#ef4444',
            ] : null,
            'branches'      => $branchesData,
            'price'         => $generalPrice,
        ];

        return response()->json(['data' => $data]);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|mimetypes:image/jpeg,image/png|max:2048',
        ]);

        $path = $this->storeImage($request);

        return response()->json([
            'data' => [
                'image' => $path,
                'image_url' => Storage::disk('public')->url($path),
            ],
        ], 201);
    }

    // ─── Update ───────────────────────────────────────────────────────────────
    public function update(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'department_id' => 'sometimes|exists:departments,id',
            'name'          => 'sometimes|string|max:255',
            'name_ar'       => 'sometimes|string|max:255',
            'code'          => "sometimes|string|max:30|unique:items,code,{$item->id}",
            'image'         => 'nullable|file|mimetypes:image/jpeg,image/png|max:2048',
            'unit'          => 'nullable|string|max:50',
            'is_active'     => 'boolean',
            'branches'      => 'sometimes|array',
            'branches.*.id' => 'required_with:branches|integer|exists:branches,id',
            'branches.*.price' => 'nullable|numeric|min:0',
            'branches.*.is_active' => 'sometimes|boolean',
            'branches.*.is_availble' => 'sometimes|boolean',
        ]);

        if ($request->hasFile('image')) {
            $data['image'] = $this->storeImage($request);
        }

        DB::transaction(function () use ($item, $data) {
            $oldImage = $item->image;

            $item->update(Arr::except($data, ['branches']));

            if (array_key_exists('branches', $data)) {
                $item->branches()->sync($this->branchSyncData($data['branches']));
            }

            if (array_key_exists('image', $data) && $oldImage) {
                Storage::disk('public')->delete($oldImage);
                Storage::disk('uploads')->delete($oldImage);
            }
        });

        return response()->json(['data' => $item->load(['department', 'branches'])]);
    }

    // ─── Destroy ──────────────────────────────────────────────────────────────
    public function destroy(Item $item): JsonResponse
    {
        $item->delete();
        return response()->json(['message' => 'تم الحذف بنجاح']);
    }

    // ─── Code Generator ───────────────────────────────────────────────────────
    private function generateCode(int $departmentId): string
    {
        $dept = Department::findOrFail($departmentId);
        $prefix = $dept->code ?? (string) $dept->id;

        $last = Item::where('code', 'like', $prefix . '%')
            ->orderByRaw('CAST(SUBSTRING(code, ?) AS UNSIGNED) DESC', [strlen($prefix) + 1])
            ->value('code');

        if ($last) {
            $suffix = (int) substr($last, strlen($prefix));
            $next   = $suffix + 1;
        } else {
            $next = 1;
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function storeImage(Request $request): string
    {
        return $request->file('image')->store('items', 'public');
    }

    /**
     * تحويل مصفوفة الفروع من الفرونت إند إلى صيغة sync للـ pivot
     * يقبل كلا الحقلين is_active و is_availble للتوافق
     */
    private function branchSyncData(array $branches): array
    {
        return collect($branches)
            ->mapWithKeys(fn(array $branch) => [
                $branch['id'] => [
                    'price'     => $branch['price'] ?? null,
                    'is_active' => $branch['is_availble'] ?? $branch['is_active'] ?? true,
                ],
            ])
            ->all();
    }
}
