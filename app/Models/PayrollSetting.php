<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * W1 — singleton payroll configuration: GL account map + GOSI/EOSB rates.
 */
final class PayrollSetting extends Model
{
    protected $fillable = [
        'basic_salary_account_id', 'housing_account_id', 'transport_account_id', 'other_earnings_account_id',
        'gosi_expense_account_id', 'gosi_payable_account_id', 'eosb_expense_account_id', 'eosb_provision_account_id',
        'net_payable_account_id', 'gosi_employee_rate', 'gosi_employer_rate', 'gosi_expat_employer_rate', 'eosb_days_per_year',
        'airticket_expense_account_id', 'airticket_provision_account_id', 'benefit_expense_account_id', 'benefit_payable_account_id',
        'loan_receivable_account_id', 'loan_bank_account_id',
    ];

    protected $casts = [
        'gosi_employee_rate' => 'decimal:3', 'gosi_employer_rate' => 'decimal:3',
        'gosi_expat_employer_rate' => 'decimal:3', 'eosb_days_per_year' => 'decimal:2',
    ];

    /** The single settings row, created with defaults on first access. */
    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }
}
