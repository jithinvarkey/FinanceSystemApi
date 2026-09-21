<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * P4.17 — Insurance products (reference data).
 */
final class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('policies.view');

        return ProductResource::collection(
            Product::query()
                ->with('lob:id,name,is_life')
                ->when($request->filled('lob_id'), fn ($q) => $q->where('lob_id', $request->integer('lob_id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
                ->orderBy('code')
                ->get(),
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::query()->create($request->validated());

        return (new ProductResource($product->load('lob')))->response()->setStatusCode(201);
    }

    public function update(StoreProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product->fresh('lob'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('policies.manage');
        $product->delete();

        return response()->json(null, 204);
    }
}
