<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $authUser = auth()->user();
            $isSuperAdmin = $authUser?->hasRole('super-admin') ?? false;
            $branchId = $isSuperAdmin
                ? $request->query('branch_id')
                : $authUser?->branch_id;

            $query = Department::with([
                'children',
                'branches' => fn ($q) => $q->withPivot('is_active'),
            ])->orderBy('code');

            if ($branchId) {
                $query->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branch_department.branch_id', $branchId)
                        ->where('branch_department.is_active', true);
                });
            }

            $departments = $query->get()->each(fn (Department $department) => $this->attachBranchIds($department));

            return response()->json(['data' => $departments]);
        } catch (\Throwable $e) {
            Log::error('Error fetching departments: ' . $e->getMessage());

            return response()->json(['data' => []]);
        }
    }

    public function tree(Request $request): JsonResponse
    {
        $authUser = auth()->user();
        $isSuperAdmin = $authUser?->hasRole('super-admin') ?? false;
        $branchId = $isSuperAdmin
            ? $request->query('branch_id')
            : $authUser?->branch_id;

        if ($branchId) {
            $departments = Department::with(['branches' => fn ($q) => $q->withPivot('is_active')])
                ->whereHas('branches', function ($q) use ($branchId) {
                    $q->where('branch_department.branch_id', $branchId)
                        ->where('branch_department.is_active', true);
                })
                ->orderBy('code')
                ->get();

            $departments->each(fn (Department $department) => $this->attachBranchIds($department));

            return response()->json(['data' => $this->buildTree($departments)]);
        }

        $query = Department::whereNull('parent_id')
            ->with('allChildren')
            ->orderBy('code');

        return response()->json(['data' => $query->get()]);
    }

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
            'branch_ids'          => 'nullable|array',
            'branch_ids.*'        => 'integer|exists:branches,id',
        ]);

        if (empty($data['code'])) {
            $data['code'] = $this->generateDeptCode($data['parent_id'] ?? null);
        }

        $branchIds = $data['branch_ids'] ?? [];
        unset($data['branch_ids']);

        $department = Department::create($data);

        if (! empty($branchIds)) {
            $department->branches()->sync($this->branchSyncData($branchIds));
        }

        $department->load('branches');
        $this->attachBranchIds($department);

        return response()->json(['data' => $department], 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'name'                => 'sometimes|string|max:255',
            'nameAr'              => 'nullable|string|max:255',
            'shortName'           => 'nullable|string|max:10',
            'code'                => "sometimes|string|max:20|unique:departments,code,{$department->id}",
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
            'branch_ids'          => 'nullable|array',
            'branch_ids.*'        => 'integer|exists:branches,id',
        ]);

        $branchIds = $data['branch_ids'] ?? [];
        unset($data['branch_ids']);

        $department->update($data);

        if ($request->has('branch_ids')) {
            $department->branches()->sync($this->branchSyncData($branchIds));
        }

        $department->load('branches');
        $this->attachBranchIds($department);

        return response()->json(['data' => $department]);
    }

    public function show(Department $department): JsonResponse
    {
        $authUser = auth()->user();
        $isSuperAdmin = $authUser?->hasRole('super-admin') ?? false;
        $branchId = $isSuperAdmin ? null : $authUser?->branch_id;

        if ($branchId && ! $department->branches()
            ->where('branch_department.branch_id', $branchId)
            ->where('branch_department.is_active', true)
            ->exists()) {
            abort(404);
        }

        $department->load(['branches' => fn ($q) => $q->withPivot('is_active')]);
        $this->attachBranchIds($department);

        return response()->json(['data' => $department]);
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    private function generateDeptCode(?int $parentId): string
    {
        if ($parentId === null) {
            $max = Department::whereNull('parent_id')->max('code');

            return (string) (((int) $max) + 1);
        }

        $parent = Department::findOrFail($parentId);
        $prefix = $parent->code ?? (string) $parent->id;

        $siblings = Department::where('parent_id', $parentId)
            ->whereNotNull('code')
            ->get()
            ->pluck('code')
            ->filter(fn ($code) => str_starts_with($code, $prefix))
            ->map(fn ($code) => (int) substr($code, strlen($prefix)))
            ->sort();

        $next = $siblings->isEmpty() ? 1 : $siblings->last() + 1;

        return $prefix . str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }

    private function branchSyncData(array $branchIds): array
    {
        return collect($branchIds)
            ->mapWithKeys(fn ($id) => [$id => ['is_active' => true]])
            ->all();
    }

    private function buildTree($departments, ?int $parentId = null)
    {
        return $departments
            ->where('parent_id', $parentId)
            ->values()
            ->map(function (Department $department) use ($departments) {
                $department->setRelation('children', $this->buildTree($departments, $department->id));

                return $department;
            });
    }

    private function attachBranchIds(Department $department): void
    {
        $department->setAttribute('branch_ids', $department->branches->pluck('id')->values());
    }
}
