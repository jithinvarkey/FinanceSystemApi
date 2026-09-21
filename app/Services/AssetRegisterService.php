<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AssetStatus;
use App\Exceptions\FinanceRuleException;
use App\Models\AssetMaintenanceLog;
use App\Models\AssetTransfer;
use App\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E10 — Fixed asset register movements: transfer an asset (cost centre /
 * location / custodian) and log maintenance events. These are tracking actions
 * with no GL impact — depreciation continues against the new cost centre.
 */
final class AssetRegisterService
{
    /**
     * Transfer an asset to a new cost centre / location / custodian. Null fields
     * leave the current value unchanged.
     */
    public function transfer(FixedAsset $asset, ?int $toCostCenterId, ?string $toLocation, ?string $toCustodian, Carbon $date, string $reason, int $userId): AssetTransfer
    {
        if ($asset->status === AssetStatus::Disposed) {
            throw new FinanceRuleException('A disposed asset cannot be transferred.');
        }

        $newCostCenter = $toCostCenterId ?? $asset->cost_center_id;
        $newLocation = $toLocation ?? $asset->location;
        $newCustodian = $toCustodian ?? $asset->custodian;

        if ((int) $newCostCenter === (int) $asset->cost_center_id && $newLocation === $asset->location && $newCustodian === $asset->custodian) {
            throw new FinanceRuleException('Nothing to transfer — the destination matches the current assignment.');
        }

        return DB::transaction(function () use ($asset, $newCostCenter, $newLocation, $newCustodian, $date, $reason, $userId): AssetTransfer {
            $row = AssetTransfer::query()->create([
                'fixed_asset_id' => $asset->id, 'transfer_date' => $date->toDateString(),
                'from_cost_center_id' => $asset->cost_center_id, 'to_cost_center_id' => $newCostCenter,
                'from_location' => $asset->location, 'to_location' => $newLocation,
                'from_custodian' => $asset->custodian, 'to_custodian' => $newCustodian,
                'reason' => $reason, 'created_by' => $userId,
            ]);

            $asset->update(['cost_center_id' => $newCostCenter, 'location' => $newLocation, 'custodian' => $newCustodian]);

            return $row;
        });
    }

    public function logMaintenance(FixedAsset $asset, Carbon $date, string $type, string $description, float $cost, ?string $vendor, int $userId): AssetMaintenanceLog
    {
        if ($cost < 0) {
            throw new FinanceRuleException('Maintenance cost cannot be negative.');
        }

        return AssetMaintenanceLog::query()->create([
            'fixed_asset_id' => $asset->id, 'maintenance_date' => $date->toDateString(),
            'type' => $type, 'description' => $description, 'cost' => round($cost, 2),
            'vendor' => $vendor, 'created_by' => $userId,
        ]);
    }

    /**
     * Transfer + maintenance history for an asset.
     *
     * @return array{transfers: \Illuminate\Support\Collection<int, AssetTransfer>, maintenance: \Illuminate\Support\Collection<int, AssetMaintenanceLog>, maintenance_total: float}
     */
    public function history(FixedAsset $asset): array
    {
        $maintenance = AssetMaintenanceLog::query()->where('fixed_asset_id', $asset->id)->latest('maintenance_date')->latest('id')->get();

        return [
            'transfers' => AssetTransfer::query()->where('fixed_asset_id', $asset->id)->latest('transfer_date')->latest('id')->get(),
            'maintenance' => $maintenance,
            'maintenance_total' => round((float) $maintenance->sum('cost'), 2),
        ];
    }
}
