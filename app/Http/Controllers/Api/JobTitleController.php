<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\JobTitleRequest;
use App\Http\Resources\JobTitleResource;
use App\Models\JobTitle;
use Illuminate\Http\JsonResponse;

class JobTitleController extends ApiController
{
    public function index(): JsonResponse
    {
        $items = JobTitle::query()->withCount('employees')->orderByDesc('is_active')->orderBy('name')->get();
        return $this->success('Job titles fetched', JobTitleResource::collection($items));
    }

    public function store(JobTitleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_active'] ??= true;
        $jobTitle = JobTitle::create($data)->loadCount('employees');
        return $this->success('Job title created', new JobTitleResource($jobTitle), 201);
    }

    public function show(JobTitle $jobTitle): JsonResponse
    {
        return $this->success('Job title fetched', new JobTitleResource($jobTitle->loadCount('employees')));
    }

    public function update(JobTitleRequest $request, JobTitle $jobTitle): JsonResponse
    {
        $jobTitle->update($request->validated());
        return $this->success('Job title updated', new JobTitleResource($jobTitle->fresh()->loadCount('employees')));
    }

    public function destroy(JobTitle $jobTitle): JsonResponse
    {
        if ($jobTitle->employees()->exists()) {
            return $this->error('لا يمكن حذف المسمى لأنه مرتبط بموظفين. عطّله بدلاً من الحذف أو غيّر مسميات الموظفين أولاً.', 422);
        }
        $jobTitle->delete();
        return $this->success('Job title deleted', []);
    }
}
