<?php
// app/Http/Controllers/Api/BranchController.php
// ✅ إصلاح الأداء:
// 1. select الحقول الضرورية فقط
// 2. withCount بدل جلب الموظفين كاملاً

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\BranchResource;
use App\Http\Requests\Api\StoreBranchRequest;
use App\Http\Requests\Api\UpdateBranchRequest;
use App\Models\Branch;

class BranchController extends ApiController
{
    public function index()
    {
        $branches = Branch::select([
            'id',
            'name',
            'address',
            'phone',
            'is_active',
            'code',
            'isMainBranch',
            'closingTime',
            'openingTime',
            'created_at',
        ])
            // ✅ withCount بدل جلب كل الموظفين
            ->withCount('employees')
            ->get();

        return $this->success('Branches fetched', BranchResource::collection($branches));
    }

    public function store(StoreBranchRequest $request)
    {
        $branch = Branch::create($request->validated());
        return $this->success('Branch created', new BranchResource($branch), 201);
    }

    public function show(Branch $branch)
    {
        $branch->load([
            'departments:id,name,color' => fn($q) => $q->withPivot('is_active'),
            'items:id,name,name_ar,code' => fn($q) => $q->withPivot(['price', 'is_active'])->with('department:id,name'),
        ]);

        return $this->success('Branch fetched', new BranchResource($branch));
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $branch->update($request->validated());
        return $this->success('Branch updated', new BranchResource($branch));
    }

    public function destroy(Branch $branch)
    {
        $branch->delete();
        return $this->success('Branch deleted', []);
    }

    public function menu(Branch $branch)
    {
        $items = $branch->items()
            ->wherePivot('is_active', true)
            ->select('items.id', 'items.name', 'items.name_ar', 'items.unit', 'items.department_id')
            ->with('department:id,name')
            ->get()
            ->groupBy(fn($item) => $item->department?->name ?? 'غير مصنّف');

        return $this->success('Branch menu fetched', [
            'branch' => $branch->only('id', 'name', 'address'),
            'menu'   => $items->map(fn($group) => $group->map(fn($item) => [
                'item_id'    => $item->id,
                'name'       => $item->name,
                'name_ar'    => $item->name_ar,
                'price'      => (float) $item->pivot->price,
                'unit'       => $item->unit,
                'department' => $item->department?->name,
            ])),
        ]);
    }
}