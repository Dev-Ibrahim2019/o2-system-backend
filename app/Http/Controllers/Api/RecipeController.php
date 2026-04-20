<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RecipeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $recipes = Recipe::with(['outputItem.group', 'ingredients.item'])
            ->when(request('output_item_id'), fn($q, $v) => $q->where('output_item_id', $v))
            ->get();

        return RecipeResource::collection($recipes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'output_item_id'         => 'required|integer|exists:items,id',
            'name'                   => 'required|string|max:255',
            'yield_qty'              => 'required|numeric|min:0.001',
            'yield_unit'             => 'required|string|max:50',
            'notes'                  => 'nullable|string',
            'ingredients'            => 'required|array|min:1',
            'ingredients.*.item_id'  => 'required|integer|exists:items,id',
            'ingredients.*.quantity' => 'required|numeric|min:0.001',
            'ingredients.*.unit'     => 'required|string|max:50',
        ]);

        $recipe = DB::transaction(function () use ($data) {
            $recipe = Recipe::create([
                'output_item_id' => $data['output_item_id'],
                'name'           => $data['name'],
                'yield_qty'      => $data['yield_qty'],
                'yield_unit'     => $data['yield_unit'],
                'notes'          => $data['notes'] ?? null,
            ]);

            foreach ($data['ingredients'] as $ingredient) {
                RecipeIngredient::create([
                    'recipe_id' => $recipe->id,
                    'item_id'   => $ingredient['item_id'],
                    'quantity'  => $ingredient['quantity'],
                    'unit'      => $ingredient['unit'],
                ]);
            }

            return $recipe;
        });

        return new RecipeResource($recipe->load(['outputItem.group', 'ingredients.item']));
    }

    /**
     * Display the specified resource.
     */
    public function show(Recipe $recipe)
    {
        $recipe->load(['outputItem.group', 'ingredients.item.group']);

        return new RecipeResource($recipe);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Recipe $recipe)
    {
        $data = $request->validate([
            'name'                   => 'sometimes|string|max:255',
            'yield_qty'              => 'sometimes|numeric|min:0.001',
            'yield_unit'             => 'sometimes|string|max:50',
            'notes'                  => 'nullable|string',
            'ingredients'            => 'sometimes|array|min:1',
            'ingredients.*.item_id'  => 'required_with:ingredients|integer|exists:items,id',
            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0.001',
            'ingredients.*.unit'     => 'required_with:ingredients|string|max:50',
        ]);

        DB::transaction(function () use ($data, $recipe) {
            $recipe->update(Arr::except($data, ['ingredients']));

            if (isset($data['ingredients'])) {
                $recipe->ingredients()->delete();

                foreach ($data['ingredients'] as $ingredient) {
                    RecipeIngredient::create([
                        'recipe_id' => $recipe->id,
                        'item_id'   => $ingredient['item_id'],
                        'quantity'  => $ingredient['quantity'],
                        'unit'      => $ingredient['unit'],
                    ]);
                }
            }
        });

        return new RecipeResource($recipe->load(['outputItem.group', 'ingredients.item']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Recipe $recipe)
    {
        $recipe->delete();

        return response()->json(['message' => 'Recipe deleted successfully']);
    }

    public function cost(Recipe $recipe): JsonResponse
    {
        $branchId = request()->validate([
            'branch_id' => 'required|integer|exists:branches,id',
        ])['branch_id'];

        $recipe->load(['ingredients.item']);
        $cost = $recipe->calculateCost($branchId);

        return response()->json([
            'recipe'     => $recipe->name,
            'branch_id'  => $branchId,
            'total_cost' => round($cost, 3),
            'breakdown'  => $recipe->ingredients->map(fn($ingredient) => [
                'item'       => $ingredient->item->name,
                'quantity'   => $ingredient->quantity,
                'unit'       => $ingredient->unit,
                'unit_price' => \App\Models\DepartmentItem::where('item_id', $ingredient->item_id)
                    ->where('branch_id', $branchId)
                    ->where('role', 'raw_material')
                    ->value('price'),
            ]),
        ]);
    }
}
