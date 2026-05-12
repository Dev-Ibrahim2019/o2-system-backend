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
    // ─── List (optionally filter by department) ───────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = Item::with(['department', 'branches'])
            ->when($request->department_id, fn($q, $id) => $q->where('department_id', $id))
            ->when($request->branch_id, fn($q, $id) =>
                $q->whereHas('branches', fn($q) => $q->where('branches.id', $id))
            )
            ->when($request->search, function ($q, $s) {
                $q->where(fn($q) =>
                    $q->where('name',    'like', "%$s%")
                      ->orWhere('name_ar','like', "%$s%")
                      ->orWhere('code',   'like', "%$s%")
                );
            })
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $query]);
    }

    // ─── Store (with optional auto-code generation) ───────────────────────────
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
        ]);

        // Auto-generate code if not provided
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
        return response()->json(['data' => $item->load(['department', 'branches'])]);
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
    /**
     * Generates the next sequential item code based on the department's own code.
     *
     * Department code examples (from the hierarchy image):
     *   Root (1) → أصناف تشغيلية (11) → قسم الشاورما (1101)
     *   → items: 1101001, 1101002, 1101003 …
     *
     * Algorithm:
     *   prefix = department.code  (e.g. "1101")
     *   Find max existing item code that starts with prefix
     *   next = max_suffix + 1, zero-padded to 3 digits
     *   result = prefix + next  (e.g. "1101004")
     */
    private function generateCode(int $departmentId): string
    {
        $dept = Department::findOrFail($departmentId);

        // If department has no code, fall back to dept id as prefix
        $prefix = $dept->code ?? (string) $dept->id;

        // Find existing items whose code starts with the prefix
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

    private function branchSyncData(array $branches): array
    {
        return collect($branches)
            ->mapWithKeys(fn(array $branch) => [
                $branch['id'] => [
                    'price' => $branch['price'] ?? null,
                    'is_active' => $branch['is_active'] ?? true,
                ],
            ])
            ->all();
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
