<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ApprovalWorkflow;
use Illuminate\Database\Seeder;

/**
 * P0.4 — Default approval workflows. Each entry maps a document type to its
 * ordered steps (permission + amount threshold). Idempotent.
 */
final class ApprovalWorkflowSeeder extends Seeder
{
    /** @var array<string, array{name: string, steps: list<array{name: string, permission: string, min: float}>}> */
    private const WORKFLOWS = [
        'journal_entry' => [
            'name' => 'Journal Entry Approval',
            'steps' => [
                ['name' => 'Controller Approval', 'permission' => 'general-ledger.approve', 'min' => 0],
            ],
        ],
        'vendor' => [
            'name' => 'Vendor Onboarding Approval',
            'steps' => [
                ['name' => 'AP Approval', 'permission' => 'accounts-payable.approve', 'min' => 0],
            ],
        ],
        'vendor_invoice' => [
            'name' => 'Vendor Invoice Approval',
            'steps' => [
                ['name' => 'AP Approval', 'permission' => 'accounts-payable.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'accounts-payable.post', 'min' => 25000],
            ],
        ],
        'vendor_payment' => [
            'name' => 'Vendor Payment Approval',
            'steps' => [
                ['name' => 'AP Approval', 'permission' => 'accounts-payable.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'accounts-payable.post', 'min' => 25000],
            ],
        ],
        'customer' => [
            'name' => 'Customer Onboarding Approval',
            'steps' => [
                ['name' => 'AR Approval', 'permission' => 'accounts-receivable.approve', 'min' => 0],
            ],
        ],
        'customer_invoice' => [
            'name' => 'Customer Invoice Approval',
            'steps' => [
                ['name' => 'AR Approval', 'permission' => 'accounts-receivable.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'accounts-receivable.post', 'min' => 25000],
            ],
        ],
        'receipt' => [
            'name' => 'Customer Receipt Approval',
            'steps' => [
                ['name' => 'AR Approval', 'permission' => 'accounts-receivable.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'accounts-receivable.post', 'min' => 25000],
            ],
        ],
        'policy' => [
            'name' => 'Policy Issuance Approval',
            'steps' => [
                ['name' => 'Commission Approval', 'permission' => 'policies.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'policies.post', 'min' => 50000],
            ],
        ],
        'endorsement' => [
            'name' => 'Policy Endorsement Approval',
            'steps' => [
                ['name' => 'Commission Approval', 'permission' => 'policies.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'policies.post', 'min' => 25000],
            ],
        ],
        'policy_cancellation' => [
            'name' => 'Policy Cancellation Approval',
            'steps' => [
                ['name' => 'Commission Approval', 'permission' => 'policies.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'policies.post', 'min' => 25000],
            ],
        ],
        'premium_collection' => [
            'name' => 'Premium Collection Approval',
            'steps' => [
                ['name' => 'Commission Approval', 'permission' => 'policies.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'policies.post', 'min' => 50000],
            ],
        ],
        'insurer_settlement' => [
            'name' => 'Insurer Settlement Approval',
            'steps' => [
                ['name' => 'Commission Approval', 'permission' => 'policies.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'policies.post', 'min' => 50000],
            ],
        ],
        'expense_claim' => [
            'name' => 'Expense Claim Approval',
            'steps' => [
                ['name' => 'AP Approval', 'permission' => 'accounts-payable.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'accounts-payable.post', 'min' => 25000],
            ],
        ],
        // Demonstrates N-level + threshold routing: the manager step only
        // engages for payments of SAR 50,000 or more.
        'payment' => [
            'name' => 'Payment Approval',
            'steps' => [
                ['name' => 'Controller Review', 'permission' => 'general-ledger.approve', 'min' => 0],
                ['name' => 'Finance Manager Sign-off', 'permission' => 'general-ledger.post', 'min' => 50000],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::WORKFLOWS as $documentType => $def) {
            $workflow = ApprovalWorkflow::query()->updateOrCreate(
                ['document_type' => $documentType],
                ['name' => $def['name'], 'is_active' => true],
            );

            foreach ($def['steps'] as $i => $step) {
                $workflow->steps()->updateOrCreate(
                    ['sequence' => $i + 1],
                    [
                        'name' => $step['name'],
                        'required_permission' => $step['permission'],
                        'min_amount' => $step['min'],
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
