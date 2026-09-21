<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * P0.1 — Seeds the finance permission catalogue, the standard roles, their
 * mappings, and assigns the demo users. Idempotent (firstOrCreate / sync).
 */
final class RbacSeeder extends Seeder
{
    /** @var array<string, string> ability => label */
    private const PERMISSIONS = [
        'finance-config.view' => 'View finance configuration',
        'finance-config.manage' => 'Manage finance configuration',
        'general-ledger.view' => 'View general ledger',
        'general-ledger.manage' => 'Create & edit journals',
        'general-ledger.approve' => 'Approve & reject journals',
        'general-ledger.post' => 'Post & reverse journals',
        'approvals.view' => 'View approval inbox & history',
        'accounts-payable.view' => 'View vendors & payables',
        'accounts-payable.manage' => 'Create & edit vendors / invoices / payments',
        'accounts-payable.approve' => 'Approve vendors & verify bank details',
        'accounts-payable.post' => 'Post vendor invoices & payments to the GL',
        'accounts-receivable.view' => 'View customers & receivables',
        'accounts-receivable.manage' => 'Create & edit customers / invoices / receipts',
        'accounts-receivable.approve' => 'Approve customers & verify bank details',
        'accounts-receivable.post' => 'Post customer invoices & receipts to the GL',
        'policies.view' => 'View policies, products & commissions',
        'policies.manage' => 'Create & edit policies / products / lines of business',
        'policies.approve' => 'Approve policies & endorsements',
        'policies.post' => 'Issue policies (post commission/premium to the GL)',
        'banking.view' => 'View bank accounts & reconciliations',
        'banking.manage' => 'Reconcile bank accounts',
    ];

    /** @var array<string, array{label: string, permissions: list<string>}> */
    private const ROLES = [
        'it-supervisor' => [
            'label' => 'IT Supervisor',
            'permissions' => ['*'],
        ],
        'finance-manager' => [
            'label' => 'Finance Manager',
            'permissions' => ['finance-config.view', 'general-ledger.view', 'general-ledger.approve', 'general-ledger.post', 'approvals.view', 'accounts-payable.view', 'accounts-payable.approve', 'accounts-payable.post', 'accounts-receivable.view', 'accounts-receivable.approve', 'accounts-receivable.post', 'policies.view', 'policies.approve', 'policies.post', 'banking.view', 'banking.manage'],
        ],
        'finance-controller' => [
            'label' => 'Finance Controller',
            'permissions' => ['finance-config.view', 'general-ledger.view', 'general-ledger.approve', 'approvals.view', 'accounts-payable.view', 'accounts-payable.approve', 'accounts-receivable.view', 'accounts-receivable.approve', 'policies.view', 'policies.approve', 'banking.view'],
        ],
        'accountant' => [
            'label' => 'Accountant',
            'permissions' => ['finance-config.view', 'general-ledger.view', 'general-ledger.manage', 'approvals.view', 'accounts-payable.view', 'accounts-payable.manage', 'accounts-receivable.view', 'accounts-receivable.manage', 'policies.view', 'policies.manage', 'banking.view', 'banking.manage'],
        ],
        'auditor' => [
            'label' => 'Auditor',
            'permissions' => ['finance-config.view', 'general-ledger.view', 'approvals.view', 'accounts-payable.view', 'accounts-receivable.view', 'policies.view', 'banking.view'],
        ],
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->mapWithKeys(fn (string $label, string $name) => [
            $name => Permission::query()->firstOrCreate(
                ['name' => $name],
                ['label' => $label, 'module' => explode('.', $name)[0]],
            ),
        ]);

        foreach (self::ROLES as $name => $def) {
            $role = Role::query()->firstOrCreate(
                ['name' => $name],
                ['label' => $def['label'], 'is_system' => true],
            );

            $ids = $def['permissions'] === ['*']
                ? $permissions->pluck('id')
                : collect($def['permissions'])->map(fn (string $p) => $permissions[$p]->id);

            $role->permissions()->sync($ids);
        }

        $this->assign('test@example.com', 'Test User', 'it-supervisor');
        $this->assign('controller@diamond.local', 'Finance Controller', 'finance-controller');
    }

    /** Ensure a demo user exists and holds exactly the given role. */
    private function assign(string $email, string $name, string $roleName): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password')],
        );

        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}
