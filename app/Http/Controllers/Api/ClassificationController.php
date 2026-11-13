<?php

namespace App\Http\Controllers\Api;

use App\Actions\SaveClassification;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClassificationIndexRequest;
use App\Http\Requests\ClassificationRequest;
use App\Http\Resources\ClassificationResource;
use App\Models\Classification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClassificationController extends Controller
{
    public function index(ClassificationIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return ClassificationResource::collection(
            Classification::filter($filters)
                ->orderBy('name')
                ->paginate($filters['per_page'] ?? 15)
                ->withQueryString()
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
