<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *  - RbacSeeder: roles, permissions, demo users.
     *  - ChartOfAccountSeeder: Diamond's live 838-account COA (must precede config).
     *  - FinanceConfigSeeder: currencies, VAT (links to COA), cost centres.
     */
    public function run(): void
    {
        $this->call([
            RbacSeeder::class,
            ChartOfAccountSeeder::class,
            FinanceConfigSeeder::class,
            ApprovalWorkflowSeeder::class,
            PolicyReferenceSeeder::class,
            IntegrationClientSeeder::class,
            OpeningBalanceEquitySeeder::class,
        ]);
    }
}
