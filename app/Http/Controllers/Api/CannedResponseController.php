<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\CannedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CannedResponseController extends ApiController
{
    /**
     * GET /api/call-center/canned-responses
     * Returns templates visible to the caller's branch, plus global (branch_id = null) ones.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:60',
        ]);

        $user = $request->user();
        $branchId = $user?->hasRole('super-admin') ? null : $user?->branch_id;

        $query = CannedResponse::query()
            ->visibleTo($branchId)
            ->when($data['search'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('title', 'like', "%{$v}%")->orWhere('body', 'like', "%{$v}%")))
            ->when($data['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->orderBy('category')
            ->orderBy('title');

        return $this->success('قوالب الردود الجاهزة', $query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'category' => 'nullable|string|max:60',
            'body' => 'required|string',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $user = $request->user();
        if (! $user?->hasRole('super-admin')) {
            $data['branch_id'] = $user?->branch_id;
        }
        $data['created_by'] = $user?->id;

        $response = CannedResponse::create($data);

        return $this->success('تم إنشاء القالب', $response, 201);
    }

    public function update(Request $request, CannedResponse $cannedResponse): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:150',
            'category' => 'nullable|string|max:60',
            'body' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $cannedResponse->update($data);

        return $this->success('تم تحديث القالب', $cannedResponse);
    }

    public function destroy(CannedResponse $cannedResponse): JsonResponse
    {
        $cannedResponse->delete();

        return $this->success('تم حذف القالب');
    }
}
