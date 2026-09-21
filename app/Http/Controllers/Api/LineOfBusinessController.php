<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLineOfBusinessRequest;
use App\Http\Resources\LineOfBusinessResource;
use App\Models\LineOfBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P4.17 — Lines of business (reference data).
 */
final class LineOfBusinessController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('policies.view');

        return LineOfBusinessResource::collection(
            LineOfBusiness::query()->orderBy('code')->get(),
        );
    }

    public function store(StoreLineOfBusinessRequest $request): JsonResponse
    {
        $lob = LineOfBusiness::query()->create($request->validated());

        return (new LineOfBusinessResource($lob))->response()->setStatusCode(201);
    }

    public function update(StoreLineOfBusinessRequest $request, LineOfBusiness $lineOfBusiness): LineOfBusinessResource
    {
        $lineOfBusiness->update($request->validated());

        return new LineOfBusinessResource($lineOfBusiness->fresh());
    }

    public function destroy(LineOfBusiness $lineOfBusiness): JsonResponse
    {
        $this->authorize('policies.manage');
        $lineOfBusiness->delete();

        return response()->json(null, 204);
    }
}
