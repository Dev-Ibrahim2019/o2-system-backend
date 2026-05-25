<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\ApiController;
use App\Http\Requests\Api\Accounting\StoreCostCenterRequest;
use App\Http\Requests\Api\Accounting\UpdateCostCenterRequest;
use App\Http\Resources\AccountingResources\CostCenterResource;
use App\Models\CostCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CostCenterController extends ApiController
{
    // ── GET /cost-centers ─────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('tree')) {
            $costCenters = CostCenter::with('childrenRecursive')
                ->whereNull('parent_id')
                ->orderBy('name')
                ->get();
        } else {
            $costCenters = CostCenter::with(['parent:id,name', 'branch:id,name'])
                ->when($request->type,      fn($q) => $q->where('type', $request->type))
                ->when($request->is_active, fn($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
                ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
                ->when($request->search,    fn($q) => $q->where('name', 'like', "%{$request->search}%"))
                ->orderBy('name')
                ->get();
        }

        return $this->success('Cost centers fetched', CostCenterResource::collection($costCenters));
    }

    // ── POST /cost-centers ────────────────────────────────────────────────────
    public function store(StoreCostCenterRequest $request): JsonResponse
    {
        $costCenter = CostCenter::create($request->validated());

        return $this->success(
            'تم إنشاء مركز التكلفة بنجاح',
            new CostCenterResource($costCenter->load(['parent', 'branch'])),
            201
        );
    }

    // ── GET /cost-centers/{cost_center} ───────────────────────────────────────
    public function show(CostCenter $costCenter): JsonResponse
    {
        $costCenter->load(['parent', 'children', 'branch']);

        return $this->success('Cost center fetched', new CostCenterResource($costCenter));
    }

    // ── PUT /cost-centers/{cost_center} ───────────────────────────────────────
    public function update(UpdateCostCenterRequest $request, CostCenter $costCenter): JsonResponse
    {
        $costCenter->update($request->validated());

        return $this->success(
            'تم تحديث مركز التكلفة بنجاح',
            new CostCenterResource($costCenter->fresh()->load(['parent', 'branch']))
        );
    }

    // ── DELETE /cost-centers/{cost_center} ────────────────────────────────────
    public function destroy(CostCenter $costCenter): JsonResponse
    {
        if ($costCenter->entries()->exists()) {
            return $this->error('لا يمكن حذف مركز تكلفة له قيود محاسبية', 422);
        }

        if ($costCenter->children()->exists()) {
            return $this->error('لا يمكن حذف مركز تكلفة له مراكز فرعية', 422);
        }

        $costCenter->delete();

        return $this->success('تم حذف مركز التكلفة بنجاح', []);
    }
    public function suggestCode(Request $request): JsonResponse
    {
        $parentId = $request->query('parent_id');

        if (!$parentId) {

            $last = CostCenter::whereNull('parent_id')
                ->whereNotNull('code')
                ->orderBy('code', 'desc')
                ->first();

            if (!$last) {
                $next = 'CC-01';
            } else {
                preg_match('/^CC-(\d+)$/', $last->code, $m);
                $next = 'CC-' . str_pad(((int)($m[1] ?? 0)) + 1, 2, '0', STR_PAD_LEFT);
            }

            return response()->json([
                'data' => [
                    'code' => $next
                ]
            ]);
        }

        $parent = CostCenter::findOrFail($parentId);

        $prefix = $parent->code . '-';

        $lastChild = CostCenter::where('parent_id', $parentId)
            ->where('code', 'like', $prefix . '%')
            ->orderBy('code', 'desc')
            ->first();

        if (!$lastChild) {
            return response()->json([
                'data' => [
                    'code' => $prefix . '01'
                ]
            ]);
        }

        $suffix = substr($lastChild->code, strlen($prefix));

        $nextSuffix = str_pad(
            ((int)$suffix) + 1,
            2,
            '0',
            STR_PAD_LEFT
        );

        return response()->json([
            'data' => [
                'code' => $prefix . $nextSuffix
            ]
        ]);
    }
}
