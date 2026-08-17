<?php
// app/Http/Controllers/Api/JobTitleController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\JobTitleRequest;
use App\Http\Resources\JobTitleResource;
use App\Models\JobTitle;
use Illuminate\Http\Request;

class JobTitleController extends ApiController
{
    public function index()
    {
        return $this->success('Job titles fetched', JobTitleResource::collection(JobTitle::with('department:id,name')->orderBy('name')->get()));
    }

    public function store(JobTitleRequest $request)
    {
        $data = $request->validated();

        $jobTitle = JobTitle::create($data);
        return $this->success('Job title created', new JobTitleResource($jobTitle->load('department:id,name')), 201);
    }

    public function show(JobTitle $jobTitle) { return $this->success('Job title fetched', new JobTitleResource($jobTitle->load('department:id,name'))); }

    public function update(JobTitleRequest $request, JobTitle $jobTitle)
    {
        $data = $request->validated();

        $jobTitle->update($data);
        return $this->success('Job title updated', new JobTitleResource($jobTitle->load('department:id,name')));
    }

    public function destroy(JobTitle $jobTitle)
    {
        $jobTitle->delete();
        return $this->success('Job title deleted', []);
    }
}
