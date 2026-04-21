<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Requests\JobTitleRequest;
use App\Http\Resources\JobTitleResource;
use App\Models\JobTitle;
use Illuminate\Http\Request;

class JobTitleController extends ApiController
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $jobTitles = JobTitle::all();

        return $this->success('Job titles fetched', JobTitleResource::collection($jobTitles));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(JobTitleRequest $request)
    {   
        return JobTitle::create($request->validated());
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
