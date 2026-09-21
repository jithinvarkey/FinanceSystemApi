<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hardening pass — pushes invariants that were previously enforced only in
 * the service layer down to the database, where they cannot be bypassed:
 *
 *  - Exactly one base currency (functional unique index).
 *  - Each journal / GL line carries exactly one of debit|credit, and > 0.
 *  - day_of_month for recurring templates stays within 1..28.
 *
 * MySQL 8.0.16+ required for CHECK enforcement. Safe/no-op on other drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Exactly one base currency: a generated column that is 1 only when
        // is_base is true (NULL otherwise) + a unique index over it. Many
        // NULLs are allowed; at most one row may hold the value 1.
        DB::statement('ALTER TABLE currencies
            ADD COLUMN base_flag TINYINT
            GENERATED ALWAYS AS (CASE WHEN is_base THEN 1 ELSE NULL END) VIRTUAL');
        DB::statement('CREATE UNIQUE INDEX currencies_single_base_unique ON currencies (base_flag)');

        // Debit-XOR-credit, and the line must move money.
        DB::statement('ALTER TABLE journal_lines
            ADD CONSTRAINT chk_journal_lines_one_side CHECK (debit = 0 OR credit = 0)');
        DB::statement('ALTER TABLE journal_lines
            ADD CONSTRAINT chk_journal_lines_nonzero CHECK (debit + credit > 0)');

        DB::statement('ALTER TABLE gl_transactions
            ADD CONSTRAINT chk_gl_one_side CHECK (debit = 0 OR credit = 0)');

        // day_of_month is documented as 1..28; enforce it.
        DB::statement('ALTER TABLE recurring_journals
            ADD CONSTRAINT chk_recurring_day_of_month CHECK (day_of_month BETWEEN 1 AND 28)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE recurring_journals DROP CONSTRAINT chk_recurring_day_of_month');
        DB::statement('ALTER TABLE gl_transactions DROP CONSTRAINT chk_gl_one_side');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT chk_journal_lines_nonzero');
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT chk_journal_lines_one_side');

        Schema::table('currencies', function ($table): void {
            $table->dropIndex('currencies_single_base_unique');
            $table->dropColumn('base_flag');
        });
    }
};
