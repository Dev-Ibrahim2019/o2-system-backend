<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemGroupResource;
use App\Models\ItemGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemGroupController extends Controller
{
    public function tree(): JsonResponse
    {
        $tree = ItemGroup::whereNull('parent_id')
            ->with('allChildren')
            ->get();

        return response()->json(ItemGroupResource::collection($tree));
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $groups = ItemGroup::with('parent')
        ->when(request('parent_id'), fn($q, $v) => $q->where('parent_id', $v))
        ->get();

        return ItemGroupResource::collection($groups);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'parent_id' => 'nullable|uuid|exists:item_groups,id',
        ]);

        $group = ItemGroup::create($data);

        return new ItemGroupResource($group->load('parent'));
    }

    /**
     * Display the specified resource.
     */
    public function show(ItemGroup $itemGroup)
    {
        $itemGroup->load(['parent', 'allChildren', 'items']);

        return new ItemGroupResource($itemGroup);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ItemGroup $itemGroup)
    {
        $data = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'parent_id' => 'nullable|uuid|exists:item_groups,id',
        ]);

        $itemGroup->update($data);

        return new ItemGroupResource($itemGroup->load('parent'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ItemGroup $itemGroup)
    {
        $itemGroup->delete();

        return response()->json(['message' => 'Group deleted successfully']);
    }
}
