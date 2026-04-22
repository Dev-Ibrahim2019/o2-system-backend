<?php
// app/Http/Controllers/Api/JobTitleController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\JobTitleResource;
use App\Models\JobTitle;
use Illuminate\Http\Request;

class JobTitleController extends ApiController
{
    public function index()
    {
        return $this->success('Job titles fetched', JobTitleResource::collection(JobTitle::all()));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $jobTitle = JobTitle::create($data);
        return $this->success('Job title created', new JobTitleResource($jobTitle), 201);
    }

    public function update(Request $request, JobTitle $jobTitle)
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $jobTitle->update($data);
        return $this->success('Job title updated', new JobTitleResource($jobTitle));
    }

    public function destroy(JobTitle $jobTitle)
    {
        $jobTitle->delete();
        return $this->success('Job title deleted', []);
    }
}
