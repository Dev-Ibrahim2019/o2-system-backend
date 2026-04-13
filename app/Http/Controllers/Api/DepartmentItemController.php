<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentItemResource;
use App\Models\DepartmentItem;
use Illuminate\Http\Request;

class DepartmentItemController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $items = DepartmentItem::with(['item.group', 'department', 'branch'])
            ->when(request('branch_id'), fn($q, $v) => $q->where('branch_id',$v))
            ->when(request('department_id') , fn($q, $v) => $q->where('department_id', $v))
            ->when(request('role'), fn($q, $v) => $q->where('role', $v))
            ->get();

        return DepartmentItemResource::collection($items);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'     => 'required|uuid|exists:branches,id',
            'department_id' => 'required|uuid|exists:departments,id',
            'item_id'       => 'required|uuid|exists:items,id',
            'role'          => 'required|in:sale,ingredient,raw_material',
            'price'         => 'nullable|numeric|min:0',
            'is_active'     => 'boolean',
        ]);

        // التحقق من عدم التكرار
        $exists = DepartmentItem::where([
            'department_id' => $data['department_id'],
            'item_id'       => $data['item_id'],
            'role'          => $data['role'],
        ])->exists();

        if ($exists) {
            return response()->json([
                'message' => 'هذا الصنف موجود بنفس الدور في هذا القسم',
            ], 422);
        }

        $departmentItem = DepartmentItem::create($data);

        return new DepartmentItemResource(
            $departmentItem->load(['item.group', 'department', 'branch'])
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(DepartmentItem $departmentItem)
    {
        return new DepartmentItemResource(
            $departmentItem->load(['item.group', 'department.branch', 'branch'])
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, DepartmentItem $departmentItem)
    {
        $data = $request->validate([
            'price'     => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            // role و department_id و item_id لا تُغيَّر — احذف وأعد الإنشاء
        ]);

        $departmentItem->update($data);

        return new DepartmentItemResource(
            $departmentItem->load(['item.group', 'department', 'branch'])
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DepartmentItem $departmentItem)
    {
        $departmentItem->delete();

        return response()->json(['message' => 'Removed successfully']);
    }
}
