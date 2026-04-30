<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\ItemResource;
use App\Http\Requests\Api\StoreItemRequest;
use App\Http\Requests\Api\UpdateItemRequest;
use App\Models\Item;
use Illuminate\Http\JsonResponse;

class ItemController extends ApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $items = Item::with(['department', 'branches'])
            ->when(request('department_id'), fn($q, $v) => $q->where('department_id', $v))
            ->when(request('branch_id'),     fn($q, $v) => $q->whereHas('branches', fn($qb) => $qb->where('branch_id', $v)))
            ->when(request('search'),        fn($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->get();

        return ItemResource::collection($items);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreItemRequest $request)
    {
        $data = $request->validated();

        $item = Item::create($data);

        return new ItemResource($item->load(['department', 'branches']));
    }

    /**
     * Display the specified resource.
     */
    public function show(Item $item)
    {
        $item->load(['department', 'branches']);

        return $this->success('Item fetched', new ItemResource($item));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateItemRequest $request, Item $item)
    {
        $data = $request->validated();

        $item->update($data);

        return new ItemResource($item->load(['department', 'branches']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Item $item)
    {
        $item->delete();

        return response()->json(['message' => 'Item deleted successfully']);
    }

    // أين يُستخدم هذا الصنف؟ (عبر الفروع)
    public function usages(Item $item)
    {
        $item->load(['branches']);

        return $this->success('Item usages fetched', [
            'item'    => $item->only('id', 'name', 'unit'),
            'branches' => $item->branches->map(fn($branch) => [
                'branch' => $branch->name,
                'price'  => $branch->pivot->price,
                'active' => $branch->pivot->is_active,
            ]),
        ]);
    }
}
