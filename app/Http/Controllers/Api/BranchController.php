<?php
// app/Http/Controllers/Api/BranchController.php

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
        $branches = Branch::all();
        // ← يرجع بـ success() wrapper حتى يتوافق مع data.data في الفرونت
        return $this->success('Branches fetched', BranchResource::collection($branches));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBranchRequest $request)
    {
        $data = $request->validated();

        $branch = Branch::create($data);

        return $this->success('Branch fetched', new BranchResource($branch));
    }

    /**
     * Display the specified resource.
     */
    public function show(Branch $branch)
    {
        $branch->load([
            'departments' => fn($q) => $q->withPivot('is_active'),
            'items' => fn($q) => $q->withPivot(['price', 'is_active'])->with('department'),
        ]);

        return $this->success('Branch fetched', new BranchResource($branch));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $data = $request->validated();

        $branch->update($data);

        return $this->success('Branch fetched', new BranchResource($branch));
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
            ->with('department')
            ->get()
            ->groupBy(fn($item) => $item->department?->name ?? 'غير مصنّف');

        return response()->json([
            'branch' => $branch->only('id', 'name', 'address'),
            'menu'   => $items->map(fn($group) => $group->map(fn($item) => [
                'item_id'    => $item->id,
                'name'       => $item->name,
                'price'      => $item->pivot->price,
                'unit'       => $item->unit,
                'department' => $item->department->name,
            ])),
        ]);
    }
}
