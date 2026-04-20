<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $items = Item::with('group')
            ->when(request('group_id'),   fn($q, $v) => $q->where('group_id', $v))
            ->when(request('base_type'),  fn($q, $v) => $q->where('base_type', $v))
            ->when(request('search'),     fn($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->get();

        return ItemResource::collection($items);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'group_id'  => 'nullable|integer|exists:item_groups,id',
            'name'      => 'required|string|max:255',
            'unit'      => 'required|string|max:50',
            'base_type' => 'required|in:sellable,ingredient,raw_material',
            'is_active' => 'boolean',
        ]);

        $item = Item::create($data);

        return new ItemResource($item->load('group'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Item $item)
    {
        $item->load(['group.parent', 'recipes.ingredients.item', 'departmentItems.department', 'departmentItems.branch']);

        return new ItemResource($item);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Item $item)
    {
         $data = $request->validate([
            'group_id'  => 'nullable|integer|exists:item_groups,id',
            'name'      => 'sometimes|string|max:255',
            'unit'      => 'sometimes|string|max:50',
            'base_type' => 'sometimes|in:sellable,ingredient,raw_material',
            'is_active' => 'boolean',
        ]);

        $item->update($data);

        return new ItemResource($item->load('group'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Item $item)
    {
        $item->delete();

        return response()->json(['message' => 'Item deleted successfully']);
    }

    // أين يُستخدم هذا الصنف؟ (عبر الأقسام والوصفات)
    public function usages(Item $item): JsonResponse
    {
        $item->load([
            'departmentItems.department',
            'departmentItems.branch',
            'recipeIngredients.recipe',
        ]);

        return response()->json([
            'item'         => $item->only('id', 'name', 'unit'),
            'departments'  => $item->departmentItems->map(fn($di) => [
                'branch'     => $di->branch?->name,
                'department' => $di->department->name,
                'role'       => $di->role,
                'price'      => $di->price,
            ]),
            'recipes'      => $item->recipeIngredients->map(fn($ri) => [
                'recipe'   => $ri->recipe->name,
                'quantity' => $ri->quantity,
                'unit'     => $ri->unit,
            ]),
        ]);
    }
}
