<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IntegrationEventStatus;
use App\Enums\IntegrationEventType;
use App\Enums\VendorType;
use App\Exceptions\FinanceRuleException;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\IntegrationEvent;
use App\Models\Policy;
use App\Models\PolicyEndorsement;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Inbound integration (P-INT) — maps PUSHed upstream events onto the existing
 * draft-creation services. Everything lands as a DRAFT for finance review; the
 * ingest never auto-posts to the GL. Idempotent by [source_system, external_id]
 * — re-pushing the same event is a no-op (duplicate), so retries are safe.
 * Every call writes one row to integration_events (the sync log).
 */
final class IntegrationIngestService
{
    public function __construct(
        private readonly PolicyService $policies,
        private readonly EndorsementService $endorsements,
    ) {
    }

    /**
     * Upsert a policy as a draft.
     *
     * @param array<string, mixed> $data
     */
    public function ingestPolicy(array $data, IntegrationClient $client): IntegrationEvent
    {
        $source = $client->source_system;
        $externalId = (string) $data['external_id'];

        $existing = Policy::query()->where('source_system', $source)->where('external_id', $externalId)->first();
        if ($existing) {
            return $this->log($client, IntegrationEventType::Policy, $externalId, IntegrationEventStatus::Duplicate, $existing, "Already ingested as {$existing->policy_number}.", $data);
        }

        try {
            $payload = $this->mapPolicy($data);
            $policy = DB::transaction(function () use ($payload, $client, $source, $externalId): Policy {
                $p = $this->policies->createDraft($payload, (int) $client->acts_as_user_id);
                $p->forceFill(['source_system' => $source, 'external_id' => $externalId])->save();

                return $p;
            });

            return $this->log($client, IntegrationEventType::Policy, $externalId, IntegrationEventStatus::Processed, $policy, "Draft {$policy->policy_number} created.", $data);
        } catch (FinanceRuleException $e) {
            $this->log($client, IntegrationEventType::Policy, $externalId, IntegrationEventStatus::Failed, null, $e->getMessage(), $data);
            throw $e;
        }
    }

    /**
     * Upsert an endorsement (against an already-ingested, issued policy) as a draft.
     *
     * @param array<string, mixed> $data
     */
    public function ingestEndorsement(array $data, IntegrationClient $client): IntegrationEvent
    {
        $source = $client->source_system;
        $externalId = (string) $data['external_id'];

        $existing = PolicyEndorsement::query()->where('source_system', $source)->where('external_id', $externalId)->first();
        if ($existing) {
            return $this->log($client, IntegrationEventType::Endorsement, $externalId, IntegrationEventStatus::Duplicate, $existing, "Already ingested as {$existing->endorsement_number}.", $data);
        }

        try {
            $policy = $this->resolvePolicy($source, (string) $data['policy_external_id']);
            $endorsement = DB::transaction(function () use ($policy, $data, $client, $source, $externalId): PolicyEndorsement {
                $e = $this->endorsements->createDraft($policy, [
                    'type' => $data['type'],
                    'delta_net_premium' => $data['delta_net_premium'],
                    'effective_date' => $data['effective_date'],
                    'reason' => $data['reason'] ?? null,
                ], (int) $client->acts_as_user_id);
                $e->forceFill(['source_system' => $source, 'external_id' => $externalId])->save();

                return $e;
            });

            return $this->log($client, IntegrationEventType::Endorsement, $externalId, IntegrationEventStatus::Processed, $endorsement, "Draft {$endorsement->endorsement_number} created.", $data);
        } catch (FinanceRuleException $e) {
            $this->log($client, IntegrationEventType::Endorsement, $externalId, IntegrationEventStatus::Failed, null, $e->getMessage(), $data);
            throw $e;
        }
    }

    /**
     * Upsert a renewal — clones an expiring policy into a new draft term.
     *
     * @param array<string, mixed> $data
     */
    public function ingestRenewal(array $data, IntegrationClient $client): IntegrationEvent
    {
        $source = $client->source_system;
        $externalId = (string) $data['external_id'];

        $existing = Policy::query()->where('source_system', $source)->where('external_id', $externalId)->first();
        if ($existing) {
            return $this->log($client, IntegrationEventType::Renewal, $externalId, IntegrationEventStatus::Duplicate, $existing, "Already ingested as {$existing->policy_number}.", $data);
        }

        try {
            $expiring = $this->resolvePolicy($source, (string) $data['policy_external_id']);
            $overrides = $this->mapRenewal($data);
            $renewal = DB::transaction(function () use ($expiring, $overrides, $client, $source, $externalId): Policy {
                $p = $this->policies->renew($expiring, $overrides, (int) $client->acts_as_user_id);
                $p->forceFill(['source_system' => $source, 'external_id' => $externalId])->save();

                return $p;
            });

            return $this->log($client, IntegrationEventType::Renewal, $externalId, IntegrationEventStatus::Processed, $renewal, "Renewal draft {$renewal->policy_number} created.", $data);
        } catch (FinanceRuleException $e) {
            $this->log($client, IntegrationEventType::Renewal, $externalId, IntegrationEventStatus::Failed, null, $e->getMessage(), $data);
            throw $e;
        }
    }

    /**
     * Resolve customer/insurer/product codes to ids and shape the createDraft payload.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mapPolicy(array $data): array
    {
        return [
            'customer_id' => $this->customerId((string) $data['customer_code']),
            'insurer_id' => $this->insurerId((string) $data['insurer_code']),
            'product_id' => $this->productId((string) $data['product_code']),
            'insurer_policy_no' => $data['insurer_policy_no'] ?? null,
            'quote_number' => $data['quote_number'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'currency_code' => $data['currency_code'] ?? null,
            'exchange_rate' => $data['exchange_rate'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'installment_count' => $data['installment_count'] ?? null,
            'net_premium' => $data['net_premium'],
            'commission_rate' => $data['commission_rate'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mapRenewal(array $data): array
    {
        $overrides = [
            'net_premium' => $data['net_premium'] ?? null,
            'commission_rate' => $data['commission_rate'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'insurer_policy_no' => $data['insurer_policy_no'] ?? null,
            'quote_number' => $data['quote_number'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'installment_count' => $data['installment_count'] ?? null,
        ];

        if (! empty($data['product_code'])) {
            $overrides['product_id'] = $this->productId((string) $data['product_code']);
        }

        return array_filter($overrides, static fn ($v): bool => $v !== null);
    }

    private function resolvePolicy(string $source, string $policyExternalId): Policy
    {
        $policy = Policy::query()->where('source_system', $source)->where('external_id', $policyExternalId)->first();
        if (! $policy) {
            throw new FinanceRuleException("No ingested policy with external id '{$policyExternalId}' for this source.");
        }

        return $policy;
    }

    private function customerId(string $code): int
    {
        $customer = Customer::query()->where('customer_code', $code)->first();
        if (! $customer) {
            throw new FinanceRuleException("Unknown customer code '{$code}'.");
        }

        return (int) $customer->id;
    }

    private function insurerId(string $code): int
    {
        $insurer = Vendor::query()->where('vendor_code', $code)->where('vendor_type', VendorType::Insurer->value)->first();
        if (! $insurer) {
            throw new FinanceRuleException("Unknown insurer code '{$code}' (must be a vendor of type insurer).");
        }

        return (int) $insurer->id;
    }

    private function productId(string $code): int
    {
        $product = Product::query()->where('code', $code)->first();
        if (! $product) {
            throw new FinanceRuleException("Unknown product code '{$code}'.");
        }

        return (int) $product->id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function log(
        IntegrationClient $client,
        IntegrationEventType $type,
        string $externalId,
        IntegrationEventStatus $status,
        ?Model $target,
        string $message,
        array $payload,
    ): IntegrationEvent {
        return IntegrationEvent::query()->create([
            'integration_client_id' => $client->id,
            'source_system' => $client->source_system,
            'event_type' => $type,
            'external_id' => $externalId,
            'status' => $status,
            'target_type' => $target ? $target::class : null,
            'target_id' => $target?->getKey(),
            'message' => mb_substr($message, 0, 500),
            'payload' => $payload,
        ]);
    }
}
