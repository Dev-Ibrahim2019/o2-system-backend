<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $branches = Branch::withCount('departments')
            ->when(request('with_departments'), fn($q) => $q->with('departments'))
            ->get();

        return BranchResource::collection($branches);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'location'  => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $branch = Branch::create($data);

        return new BranchResource($branch);
    }

    /**
     * Display the specified resource.
     */
    public function show(Branch $branch)
    {
        $branch->load('departments.departmentItems.item.group');

        return new BranchResource($branch);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Branch $branch)
    {
        $data = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'location'  => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        $branch->update($data);

        return new BranchResource($branch);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Branch $branch)
    {
        $branch->delete();

        return response()->json(['message' => 'Branch deleted successfully']);
    }

    public function menu(Branch $branch)
    {
        $items = $branch->departmentItems()
            ->where('role', 'sale')
            ->where('is_active', true)
            ->with(['item.group', 'department'])
            ->get()
            ->groupBy(fn($di) => $di->item->group?->name ?? 'غير مصنّف');

        return response()->json([
            'branch' => $branch->only('id', 'name', 'location'),
            'menu'   => $items->map(fn($group) => $group->map(fn($di) => [
                'department_item_id' => $di->id,
                'name'               => $di->item->name,
                'price'              => $di->price,
                'unit'               => $di->item->unit,
                'department'         => $di->department->name,
            ])),
        ]);
    }
}
