<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFixedAssetRequest;
use App\Http\Resources\FixedAssetResource;
use App\Models\FixedAsset;
use App\Services\FixedAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * P7 — Fixed assets + depreciation. Reuses the GL permission set
 * (view / manage / post).
 */
final class FixedAssetController extends Controller
{
    public function __construct(private readonly FixedAssetService $service)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('general-ledger.view');

        $assets = FixedAsset::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->latest('id')->limit(300)->get();

        return FixedAssetResource::collection($assets);
    }

    public function store(StoreFixedAssetRequest $request): JsonResponse
    {
        $asset = $this->service->createAsset($request->validated(), (int) $request->user()->id);

        return (new FixedAssetResource($asset))->response()->setStatusCode(201);
    }

    public function show(FixedAsset $fixedAsset): FixedAssetResource
    {
        $this->authorize('general-ledger.view');

        return new FixedAssetResource($fixedAsset->load('entries'));
    }

    /** Post one month's depreciation across all eligible assets. */
    public function runDepreciation(Request $request): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate(['as_of' => ['required', 'date']]);

        $result = $this->service->runDepreciation(Carbon::parse($data['as_of']), (int) $request->user()->id);

        return response()->json(['data' => $result]);
    }

    /** F23 — dispose of (sell/scrap) an asset. */
    public function dispose(Request $request, FixedAsset $fixedAsset): FixedAssetResource
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'proceeds' => ['required', 'numeric', 'min:0'],
            'cash_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'gain_loss_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'disposal_date' => ['required', 'date'],
            'method' => ['nullable', 'in:sale,scrap'],
        ]);

        $this->service->dispose(
            $fixedAsset, (float) $data['proceeds'], (int) $data['cash_account_id'], (int) $data['gain_loss_account_id'],
            Carbon::parse($data['disposal_date']), (int) $request->user()->id, $data['method'] ?? 'sale',
        );

        return new FixedAssetResource($fixedAsset->fresh());
    }

    /** F25 — impair an asset (write down its carrying value). */
    public function impair(Request $request, FixedAsset $fixedAsset): FixedAssetResource
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'impairment_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'date' => ['required', 'date'],
        ]);

        $asset = $this->service->impair($fixedAsset, (int) $data['impairment_account_id'], (float) $data['amount'], Carbon::parse($data['date']), (int) $request->user()->id);

        return new FixedAssetResource($asset);
    }

    /** F25 — revalue an asset upward to a new carrying value. */
    public function revalue(Request $request, FixedAsset $fixedAsset): FixedAssetResource
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate([
            'new_value' => ['required', 'numeric', 'min:0.01'],
            'reserve_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'date' => ['required', 'date'],
        ]);

        $asset = $this->service->revalue($fixedAsset, (int) $data['reserve_account_id'], (float) $data['new_value'], Carbon::parse($data['date']), (int) $request->user()->id);

        return new FixedAssetResource($asset);
    }

    /** F25 — capitalise a CWIP asset so it starts depreciating. */
    public function capitalize(Request $request, FixedAsset $fixedAsset): FixedAssetResource
    {
        $this->authorize('general-ledger.post');
        $data = $request->validate(['in_service_date' => ['required', 'date']]);

        $asset = $this->service->capitalize($fixedAsset, Carbon::parse($data['in_service_date']));

        return new FixedAssetResource($asset);
    }
}
