<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaveClassification;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClassificationRequest;
use App\Http\Resources\ClassificationResource;
use App\Models\Classification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClassificationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ClassificationResource::collection(
            Classification::orderBy('name')->paginate(2)
        );
    }

    public function store(ClassificationRequest $request, SaveClassification $save): JsonResponse
    {
        return (new ClassificationResource($save->handle($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Classification $classification): ClassificationResource
    {
        return new ClassificationResource($classification);
    }

    public function update(ClassificationRequest $request, Classification $classification, SaveClassification $save): ClassificationResource
    {
        return new ClassificationResource($save->handle($request->validated(), $classification));
    }

    public function destroy(Classification $classification): Response
    {
        $classification->delete();

        return response()->noContent();
    }
}
