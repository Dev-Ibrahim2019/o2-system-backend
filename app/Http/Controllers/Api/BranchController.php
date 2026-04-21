<?php
// app/Http/Controllers/Api/BranchController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends ApiController
{
    public function index()
    {
        $branches = Branch::all();
        // ← يرجع بـ success() wrapper حتى يتوافق مع data.data في الفرونت
        return $this->success('Branches fetched', BranchResource::collection($branches));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'address'      => ['nullable', 'string'],
            'phone'        => ['nullable', 'string'],
            'code'         => ['required', 'string'],
            'isMainBranch' => ['boolean'],
            'openingTime'  => ['nullable', 'string'],
            'closingTime'  => ['nullable', 'string'],
            'is_active'    => ['boolean'],
        ]);

        $branch = Branch::create($data);
        return $this->success('Branch created', new BranchResource($branch), 201);
    }

    public function show(Branch $branch)
    {
        return $this->success('Branch fetched', new BranchResource($branch));
    }

    public function update(Request $request, Branch $branch)
    {
        $branch->update($request->all());
        return $this->success('Branch updated', new BranchResource($branch));
    }

    public function destroy(Branch $branch)
    {
        $branch->delete();
        return $this->success('Branch deleted', []);
    }
}
