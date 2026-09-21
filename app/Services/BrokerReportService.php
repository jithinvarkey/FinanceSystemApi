<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\GlTransaction;
use App\Models\InsurerSettlement;
use App\Models\Policy;
use App\Models\PolicyCancellation;
use App\Models\PolicyEndorsement;
use App\Models\PremiumCollection;
use App\Models\Vendor;
use App\Services\Concerns\ResolvesPostableAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * P4.17 Slice D (doc §12) — Broker reporting: the insurer bordereaux.
 *
 * Read-only. The position summary reconciles each insurer from the policy
 * ledger; the statement is a chronological ledger of every movement on the
 * insurer's payable account — issuance, endorsement, cancellation, settlement —
 * with a running balance (what the broker owes the insurer).
 */
final class BrokerReportService
{
    use ResolvesPostableAccount;

    /** @var list<string> */
    private const STATUSES = ['issued', 'cancelled'];

    /** Ageing bucket boundaries, in days past the reference date. */
    private const BUCKETS = ['1_30', '31_60', '61_90', '91_120', '121_365', 'over_365'];

    /** GL source_type → human ledger label. */
    private const EVENT_LABELS = [
        Policy::class => 'issuance',
        PolicyEndorsement::class => 'endorsement',
        PolicyCancellation::class => 'cancellation',
        PremiumCollection::class => 'collection',
        InsurerSettlement::class => 'settlement',
    ];

    /**
     * Per-insurer net position — one row per insurer plus grand totals.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function insurerPositions(): array
    {
        $policies = Policy::query()
            ->whereIn('status', self::STATUSES)
            ->with('insurer:id,name,vendor_code')
            ->get();

        /** @var Collection<int, array<string, mixed>> $byInsurer */
        $byInsurer = collect();

        foreach ($policies as $policy) {
            $insurerId = (int) $policy->insurer_id;
            $row = $byInsurer->get($insurerId) ?? [
                'insurer_id' => $insurerId,
                'insurer_code' => $policy->insurer?->vendor_code,
                'insurer_name' => $policy->insurer?->name ?? '(unknown)',
                'policies' => 0,
                'gross_premium' => 0.0,
                'commission' => 0.0,
                'net_due' => 0.0,
                'settled' => 0.0,
                'outstanding' => 0.0,
            ];

            $commission = round((float) $policy->commission_amount + (float) $policy->commission_tax_amount, 2);

            $row['policies']++;
            $row['gross_premium'] = round($row['gross_premium'] + (float) $policy->gross_premium, 2);
            $row['commission'] = round($row['commission'] + $commission, 2);
            $row['net_due'] = round($row['net_due'] + $policy->netDueToInsurer(), 2);
            $row['settled'] = round($row['settled'] + (float) $policy->insurer_settled, 2);
            $row['outstanding'] = round($row['outstanding'] + $policy->insurerBalanceDue(), 2);

            $byInsurer->put($insurerId, $row);
        }

        $rows = $byInsurer->values()->sortByDesc('outstanding')->values()->all();

        return ['rows' => $rows, 'totals' => $this->columnTotals($rows)];
    }

    /**
     * Per-insurer statement — a chronological ledger of every posted movement on
     * the insurer's payable account. Each source document (policy issuance,
     * endorsement, cancellation, settlement) is one line: an increase raises what
     * the broker owes the insurer, a decrease (settlement / refund / clawback)
     * reduces it. Running balance = amount currently owed to the insurer.
     *
     * @return array{
     *     insurer: array<string, mixed>,
     *     entries: list<array<string, mixed>>,
     *     closing_balance: float
     * }
     */
    public function insurerStatement(Vendor $insurer): array
    {
        $entries = [];
        $balance = 0.0;

        $payableAccountId = $insurer->default_payable_account_id !== null
            ? $this->resolvePostable((int) $insurer->default_payable_account_id)
            : null;

        if ($payableAccountId !== null) {
            $policyIds = Policy::query()->where('insurer_id', $insurer->id)->pluck('id')->all();

            // Per-document metadata (reference number + the policy it belongs to),
            // keyed by "<source_type>:<id>", plus a policy → number/customer lookup.
            $meta = $this->documentMeta($insurer->id, $policyIds);
            $policyInfo = $this->policyInfo($policyIds);

            // GL rows on the insurer's payable account, for this insurer's documents only.
            $rows = GlTransaction::query()
                ->where('account_id', $payableAccountId)
                ->where(function ($q) use ($policyIds, $meta): void {
                    $q->where(fn ($w) => $w->where('source_type', Policy::class)->whereIn('source_id', $policyIds))
                        ->orWhere(fn ($w) => $w->where('source_type', PolicyEndorsement::class)->whereIn('source_id', $this->idsFor($meta, PolicyEndorsement::class)))
                        ->orWhere(fn ($w) => $w->where('source_type', PolicyCancellation::class)->whereIn('source_id', $this->idsFor($meta, PolicyCancellation::class)))
                        ->orWhere(fn ($w) => $w->where('source_type', InsurerSettlement::class)->whereIn('source_id', $this->idsFor($meta, InsurerSettlement::class)));
                })
                ->orderBy('id')
                ->get(['transaction_date', 'account_id', 'base_debit', 'base_credit', 'source_type', 'source_id']);

            // Group by source document — one ledger line per document.
            foreach ($rows->groupBy(fn ($r): string => $r->source_type.':'.$r->source_id) as $key => $group) {
                $credit = round((float) $group->sum('base_credit'), 2);
                $debit = round((float) $group->sum('base_debit'), 2);
                $balance = round($balance + $credit - $debit, 2);

                $first = $group->first();
                $policyId = $meta[$key]['policy_id'] ?? null;
                $policy = $policyId !== null ? ($policyInfo[$policyId] ?? null) : null;

                $entries[] = [
                    'date' => $first->transaction_date?->toDateString(),
                    'type' => self::EVENT_LABELS[$first->source_type] ?? 'movement',
                    'reference' => $meta[$key]['reference'] ?? '—',
                    'policy_number' => $policy['policy_number'] ?? null,
                    'customer_name' => $policy['customer_name'] ?? null,
                    'credit' => $credit,
                    'debit' => $debit,
                    'balance' => $balance,
                ];
            }
        }

        return [
            'insurer' => [
                'id' => $insurer->id,
                'vendor_code' => $insurer->vendor_code,
                'name' => $insurer->name,
            ],
            'entries' => $entries,
            'closing_balance' => round($balance, 2),
        ];
    }

    /**
     * Build a "<source_type>:<id>" → {reference, policy_id} map for this insurer's
     * policies, endorsements, cancellations and settlements. A settlement maps to a
     * policy only when it settles exactly one (else null — it spans several).
     *
     * @param  list<int>  $policyIds
     * @return array<string, array{reference: string, policy_id: ?int}>
     */
    private function documentMeta(int $insurerId, array $policyIds): array
    {
        $meta = [];

        foreach (Policy::query()->whereIn('id', $policyIds)->get(['id', 'policy_number']) as $p) {
            $meta[Policy::class.':'.$p->id] = ['reference' => $p->policy_number, 'policy_id' => (int) $p->id];
        }
        foreach (PolicyEndorsement::query()->whereIn('policy_id', $policyIds)->where('status', 'posted')->get(['id', 'endorsement_number', 'policy_id']) as $e) {
            $meta[PolicyEndorsement::class.':'.$e->id] = ['reference' => $e->endorsement_number, 'policy_id' => (int) $e->policy_id];
        }
        foreach (PolicyCancellation::query()->whereIn('policy_id', $policyIds)->where('status', 'posted')->get(['id', 'cancellation_number', 'policy_id']) as $c) {
            $meta[PolicyCancellation::class.':'.$c->id] = ['reference' => $c->cancellation_number, 'policy_id' => (int) $c->policy_id];
        }

        $settlements = InsurerSettlement::query()
            ->where('insurer_id', $insurerId)->where('status', 'posted')
            ->with('allocations:id,insurer_settlement_id,policy_id')
            ->get(['id', 'settlement_number']);
        foreach ($settlements as $s) {
            $policies = $s->allocations->pluck('policy_id')->unique();
            $meta[InsurerSettlement::class.':'.$s->id] = [
                'reference' => $s->settlement_number,
                'policy_id' => $policies->count() === 1 ? (int) $policies->first() : null,
            ];
        }

        return $meta;
    }

    /**
     * Policy id → {policy_number, customer_name} lookup.
     *
     * @param  list<int>  $policyIds
     * @return array<int, array{policy_number: string, customer_name: ?string}>
     */
    private function policyInfo(array $policyIds): array
    {
        return Policy::query()
            ->whereIn('id', $policyIds)
            ->with(['customer:id,name', 'insurer:id,name'])
            ->get(['id', 'policy_number', 'customer_id', 'insurer_id'])
            ->mapWithKeys(fn (Policy $p): array => [
                (int) $p->id => [
                    'policy_number' => $p->policy_number,
                    'customer_name' => $p->customer?->name,
                    'insurer_name' => $p->insurer?->name,
                ],
            ])
            ->all();
    }

    /**
     * Pull the document ids of a given source type out of the metadata map.
     *
     * @param  array<string, array{reference: string, policy_id: ?int}>  $meta
     * @return list<int>
     */
    private function idsFor(array $meta, string $sourceType): array
    {
        $prefix = $sourceType.':';
        $ids = [];
        foreach (array_keys($meta) as $key) {
            if (str_starts_with($key, $prefix)) {
                $ids[] = (int) substr($key, strlen($prefix));
            }
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function columnTotals(array $rows): array
    {
        $totals = array_fill_keys(['gross_premium', 'commission', 'net_due', 'settled', 'outstanding'], 0.0);

        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] = round($totals[$key] + (float) $row[$key], 2);
            }
        }

        return $totals;
    }

    // ----- customer statement (premium receivable ledger) -----

    /**
     * Per-customer Statement of Account — a chronological ledger of every posted
     * movement on the customer's premium-receivable account, in the client's SOA
     * layout (type code, policy, insurer, endorsement no, description, due date,
     * debit/credit, running balance + totals). Optionally scoped to one policy.
     *
     * @return array{
     *     customer: array<string, mixed>,
     *     policy_number: ?string,
     *     entries: list<array<string, mixed>>,
     *     totals: array{debit: float, credit: float},
     *     closing_balance: float
     * }
     */
    public function customerStatement(Customer $customer, ?int $policyId = null): array
    {
        $entries = [];
        $balance = $totalDebit = $totalCredit = 0.0;

        $receivableId = $customer->default_receivable_account_id !== null
            ? $this->resolvePostable((int) $customer->default_receivable_account_id)
            : null;

        $scopePolicy = null;

        if ($receivableId !== null) {
            $policyIds = Policy::query()
                ->where('customer_id', $customer->id)
                ->when($policyId !== null, fn ($q) => $q->where('id', $policyId))
                ->pluck('id')->all();

            $meta = $this->customerDocumentMeta($policyIds);
            $policyInfo = $this->policyInfo($policyIds);
            $scopePolicy = $policyId !== null ? ($policyInfo[$policyId]['policy_number'] ?? null) : null;

            $rows = GlTransaction::query()
                ->where('account_id', $receivableId)
                ->where(function ($q) use ($policyIds, $meta): void {
                    $q->where(fn ($w) => $w->where('source_type', Policy::class)->whereIn('source_id', $policyIds))
                        ->orWhere(fn ($w) => $w->where('source_type', PolicyEndorsement::class)->whereIn('source_id', $this->idsFor($meta, PolicyEndorsement::class)))
                        ->orWhere(fn ($w) => $w->where('source_type', PolicyCancellation::class)->whereIn('source_id', $this->idsFor($meta, PolicyCancellation::class)))
                        ->orWhere(fn ($w) => $w->where('source_type', PremiumCollection::class)->whereIn('source_id', $this->idsFor($meta, PremiumCollection::class)));
                })
                ->orderBy('id')
                ->get(['transaction_date', 'account_id', 'base_debit', 'base_credit', 'source_type', 'source_id']);

            foreach ($rows->groupBy(fn ($r): string => $r->source_type.':'.$r->source_id) as $key => $group) {
                $debit = round((float) $group->sum('base_debit'), 2);
                $credit = round((float) $group->sum('base_credit'), 2);
                $balance = round($balance + $debit - $credit, 2);
                $totalDebit = round($totalDebit + $debit, 2);
                $totalCredit = round($totalCredit + $credit, 2);

                $m = $meta[$key] ?? [];
                $pId = $m['policy_id'] ?? null;
                $policy = $pId !== null ? ($policyInfo[$pId] ?? null) : null;

                $entries[] = [
                    'date' => $group->first()->transaction_date?->toDateString(),
                    'type' => $m['type_code'] ?? '—',
                    'policy_number' => $policy['policy_number'] ?? null,
                    'insurer_name' => $policy['insurer_name'] ?? null,
                    'client_code' => $customer->customer_code,
                    'client_name' => $customer->name,
                    'endorsement_no' => $m['endorsement_no'] ?? null,
                    'description' => $m['description'] ?? null,
                    'due_date' => $m['due_date'] ?? null,
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => $balance,
                ];
            }
        }

        return [
            'customer' => ['id' => $customer->id, 'customer_code' => $customer->customer_code, 'name' => $customer->name],
            'policy_number' => $scopePolicy,
            'entries' => $entries,
            'totals' => ['debit' => round($totalDebit, 2), 'credit' => round($totalCredit, 2)],
            'closing_balance' => round($balance, 2),
        ];
    }

    // ----- ageing -----

    /**
     * Premium-receivable ageing — one row per policy with outstanding premium
     * (gross less collected), aged into 1-30 / 31-60 / 61-90 / 91-120 / 121-365 /
     * >365 day buckets by installment due date (or policy start for full pay).
     *
     * @return array{as_of: string, buckets: list<string>, rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function customerAging(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->startOfDay();

        $policies = Policy::query()
            ->whereIn('status', self::STATUSES)
            ->with(['customer:id,name', 'insurer:id,name', 'product:id,name,lob_id', 'product.lob:id,name', 'installments'])
            ->orderBy('policy_number')
            ->get();

        $rows = [];

        foreach ($policies as $policy) {
            if ($policy->premiumBalanceDue() <= 0) {
                continue;
            }

            $row = $this->newPolicyAgingRow($policy);

            // Installment policies age each unpaid installment by its own due date.
            $installments = $policy->installments->filter(fn ($i) => $i->balanceDue() > 0);
            if ($installments->isNotEmpty()) {
                foreach ($installments as $installment) {
                    $this->addPolicyBucket($row, $installment->balanceDue(), $installment->due_date, $asOf);
                }
            } else {
                $this->addPolicyBucket($row, $policy->premiumBalanceDue(), $policy->start_date, $asOf);
            }

            $rows[] = $row;
        }

        return $this->policyAgingReport($asOf, $rows);
    }

    /**
     * Premium-payable (insurer) ageing — one row per policy with the net premium
     * still owed to the insurer, aged from the policy start.
     *
     * @return array{as_of: string, buckets: list<string>, rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function insurerAging(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->startOfDay();

        $policies = Policy::query()
            ->whereIn('status', self::STATUSES)
            ->with(['customer:id,name', 'insurer:id,name', 'product:id,name,lob_id', 'product.lob:id,name'])
            ->orderBy('policy_number')
            ->get();

        $rows = [];

        foreach ($policies as $policy) {
            if ($policy->insurerBalanceDue() <= 0) {
                continue;
            }

            $row = $this->newPolicyAgingRow($policy);
            $this->addPolicyBucket($row, $policy->insurerBalanceDue(), $policy->start_date, $asOf);
            $rows[] = $row;
        }

        return $this->policyAgingReport($asOf, $rows);
    }

    /**
     * Build a "<source_type>:<id>" → SOA-row metadata map for the documents that
     * touch a customer's receivable. Mirrors the client's SOA columns: a type
     * code (INS/PMT/INV/CM), endorsement number, description and due date.
     *
     * @param  list<int>  $policyIds
     * @return array<string, array{reference: string, policy_id: ?int, type_code: string, endorsement_no: ?string, description: string, due_date: ?string}>
     */
    private function customerDocumentMeta(array $policyIds): array
    {
        $meta = [];

        foreach (Policy::query()->whereIn('id', $policyIds)->get(['id', 'policy_number', 'start_date']) as $p) {
            $meta[Policy::class.':'.$p->id] = [
                'reference' => $p->policy_number, 'policy_id' => (int) $p->id,
                'type_code' => 'INS', 'endorsement_no' => null,
                'description' => 'New policy issued', 'due_date' => $p->start_date?->toDateString(),
            ];
        }
        foreach (PolicyEndorsement::query()->whereIn('policy_id', $policyIds)->where('status', 'posted')->get(['id', 'endorsement_number', 'policy_id', 'direction', 'effective_date']) as $e) {
            $additional = $e->direction === 'additional';
            $meta[PolicyEndorsement::class.':'.$e->id] = [
                'reference' => $e->endorsement_number, 'policy_id' => (int) $e->policy_id,
                'type_code' => $additional ? 'INV' : 'CM', 'endorsement_no' => $e->endorsement_number,
                'description' => ($additional ? 'Policy Endorsement ' : 'Credit Pol Decrease ').$e->endorsement_number,
                'due_date' => $e->effective_date?->toDateString(),
            ];
        }
        foreach (PolicyCancellation::query()->whereIn('policy_id', $policyIds)->where('status', 'posted')->get(['id', 'cancellation_number', 'policy_id', 'cancellation_date']) as $c) {
            $meta[PolicyCancellation::class.':'.$c->id] = [
                'reference' => $c->cancellation_number, 'policy_id' => (int) $c->policy_id,
                'type_code' => 'CM', 'endorsement_no' => $c->cancellation_number,
                'description' => 'Policy Cancellation '.$c->cancellation_number,
                'due_date' => $c->cancellation_date?->toDateString(),
            ];
        }
        foreach (PremiumCollection::query()->whereIn('policy_id', $policyIds)->where('status', 'posted')->get(['id', 'collection_number', 'policy_id']) as $pc) {
            $meta[PremiumCollection::class.':'.$pc->id] = [
                'reference' => $pc->collection_number, 'policy_id' => (int) $pc->policy_id,
                'type_code' => 'PMT', 'endorsement_no' => null,
                'description' => 'Payment Received', 'due_date' => null,
            ];
        }

        return $meta;
    }

    /**
     * A blank per-policy ageing row: policy / customer / type (class of business) /
     * insurer attributes plus zeroed outstanding and buckets.
     *
     * @return array<string, mixed>
     */
    private function newPolicyAgingRow(Policy $policy): array
    {
        return array_merge([
            'policy_id' => (int) $policy->id,
            'policy_number' => $policy->policy_number,
            'customer_name' => $policy->customer?->name,
            'type' => $policy->product?->lob?->name ?? $policy->product?->name,
            'insurer_name' => $policy->insurer?->name,
            'outstanding' => 0.0,
        ], array_fill_keys(self::BUCKETS, 0.0));
    }

    /**
     * Add an outstanding amount into the right ageing bucket of $row, in place.
     *
     * @param  array<string, mixed>  $row
     */
    private function addPolicyBucket(array &$row, float $amount, ?Carbon $reference, Carbon $asOf): void
    {
        $amount = round($amount, 2);
        $ref = ($reference ?? $asOf)->copy()->startOfDay();
        $bucket = $this->bucketFor((int) $ref->diffInDays($asOf, false));

        $row[$bucket] = round($row[$bucket] + $amount, 2);
        $row['outstanding'] = round($row['outstanding'] + $amount, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{as_of: string, buckets: list<string>, rows: list<array<string, mixed>>, totals: array<string, float>}
     */
    private function policyAgingReport(Carbon $asOf, array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => $b['outstanding'] <=> $a['outstanding']);

        $totals = array_fill_keys(['outstanding', ...self::BUCKETS], 0.0);
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] = round($totals[$key] + (float) $row[$key], 2);
            }
        }

        return ['as_of' => $asOf->toDateString(), 'buckets' => self::BUCKETS, 'rows' => array_values($rows), 'totals' => $totals];
    }

    private function bucketFor(int $daysPast): string
    {
        return match (true) {
            $daysPast <= 30 => '1_30',
            $daysPast <= 60 => '31_60',
            $daysPast <= 90 => '61_90',
            $daysPast <= 120 => '91_120',
            $daysPast <= 365 => '121_365',
            default => 'over_365',
        };
    }
}
