<?php
// app/Http/Controllers/Api/DepartmentController.php
// Add/update the `tree` endpoint and ensure `code` is part of the model

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    // ─── Flat list ────────────────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $departments = Department::with('children')
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $departments]);
    }

    // ─── Nested tree (used by frontend ItemsTable) ────────────────────────────
    public function tree(): JsonResponse
    {
        // Only root nodes; children are eager-loaded recursively
        $roots = Department::whereNull('parent_id')
            ->with('allChildren')     // see model recursive relation below
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $roots]);
    }

    // ─── Store ────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                => 'required|string|max:255',
            'nameAr'              => 'nullable|string|max:255',
            'shortName'           => 'nullable|string|max:10',
            'code'                => 'nullable|string|max:20|unique:departments,code',
            'icon'                => 'nullable|string',
            'color'               => 'nullable|string|max:20',
            'type'                => 'required|in:section,department,unit',
            'status'              => 'nullable|in:ACTIVE,BUSY,INACTIVE',
            'stationNumber'       => 'nullable|string',
            'defaultPrepTime'     => 'integer|min:0',
            'maxConcurrentOrders' => 'integer|min:1',
            'hasKds'              => 'boolean',
            'autoPrintTicket'     => 'boolean',
            'parent_id'           => 'nullable|exists:departments,id',
        ]);

        // Auto-generate code based on parent if not provided
        if (empty($data['code'])) {
            $data['code'] = $this->generateDeptCode($data['parent_id'] ?? null);
        }

        $dept = Department::create($data);

        return response()->json(['data' => $dept], 201);
    }

    // ─── Update ───────────────────────────────────────────────────────────────
    public function update(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'nameAr'              => 'nullable|string|max:255',
            'shortName'           => 'nullable|string|max:10',
            'code'                => "nullable|string|max:20|unique:departments,code,{$department->id}",
            'icon'                => 'nullable|string',
            'color'               => 'nullable|string|max:20',
            'type'                => 'sometimes|in:section,department,unit',
            'status'              => 'nullable|in:ACTIVE,BUSY,INACTIVE',
            'stationNumber'       => 'nullable|string',
            'defaultPrepTime'     => 'integer|min:0',
            'maxConcurrentOrders' => 'integer|min:1',
            'hasKds'              => 'boolean',
            'autoPrintTicket'     => 'boolean',
            'parent_id'           => 'nullable|exists:departments,id',
        ]);

        $department->update($data);

        return response()->json(['data' => $department]);
    }

    // ─── Destroy ──────────────────────────────────────────────────────────────
    public function destroy(Department $department): JsonResponse
    {
        $department->delete();
        return response()->json(['message' => 'تم الحذف بنجاح']);
    }

    // ─── Code Generator ───────────────────────────────────────────────────────
    /**
     * Hierarchy from image:
     *   Root → 1 (root dept, code=1)
     *     └─ أصناف تشغيلية → 11
     *           ├─ قسم الشاورما  → 1101
     *           └─ قسم الإبداعات → 1102
     *
     * Logic:
     *   - Root depts: 1, 2, 3 …
     *   - Children: parent.code + 2-digit index  (01, 02 …)
     *   - Grand-children: same pattern, always appending 2 digits
     */
    private function generateDeptCode(?int $parentId): string
    {
        if ($parentId === null) {
            // Root level — find max single-digit or root code
            $max = Department::whereNull('parent_id')->max('code');
            return (string)(((int)$max) + 1);
        }

        $parent = Department::findOrFail($parentId);
        $prefix = $parent->code ?? (string) $parent->id;

        // Siblings: codes that start with prefix and have exactly 2 more digits
        $siblings = Department::where('parent_id', $parentId)
            ->whereNotNull('code')
            ->get()
            ->pluck('code')
            ->filter(fn($c) => str_starts_with($c, $prefix))
            ->map(fn($c)    => (int) substr($c, strlen($prefix)))
            ->sort();

        $next = $siblings->isEmpty() ? 1 : $siblings->last() + 1;

        return $prefix . str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }
}
