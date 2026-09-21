<?php

declare(strict_types=1);

/**
 * P0.5 — Document numbering defaults.
 *
 * Each document type gets a gap-free, optionally year-reset sequence. These
 * values seed a `number_sequences` row on first use; thereafter the row is the
 * source of truth (editable via the number-ranges config screen, P1.8).
 */
return [
    'separator' => '-',

    'default' => ['prefix' => 'DOC', 'padding' => 6, 'yearly' => true],

    'types' => [
        'journal_entry'    => ['prefix' => 'JV',   'padding' => 6, 'yearly' => true],
        'opening_balance'  => ['prefix' => 'OB',   'padding' => 6, 'yearly' => true],
        'vendor'           => ['prefix' => 'VEND', 'padding' => 6, 'yearly' => true],
        'vendor_invoice'   => ['prefix' => 'VINV', 'padding' => 6, 'yearly' => true],
        'vendor_payment'   => ['prefix' => 'PMT',  'padding' => 6, 'yearly' => true],
        'customer'         => ['prefix' => 'CUST', 'padding' => 6, 'yearly' => true],
        'policy'           => ['prefix' => 'POL',  'padding' => 6, 'yearly' => true],
        'endorsement'      => ['prefix' => 'END',  'padding' => 6, 'yearly' => true],
        'policy_cancellation' => ['prefix' => 'CANC', 'padding' => 6, 'yearly' => true],
        'customer_invoice' => ['prefix' => 'SINV', 'padding' => 6, 'yearly' => true],
        'customer_advance' => ['prefix' => 'ADV', 'padding' => 6, 'yearly' => true],
        'customer_credit_note' => ['prefix' => 'CN', 'padding' => 6, 'yearly' => true],
        'customer_debit_note' => ['prefix' => 'DN', 'padding' => 6, 'yearly' => true],
        'vendor_credit_note' => ['prefix' => 'VCN', 'padding' => 6, 'yearly' => true],
        'vendor_debit_note' => ['prefix' => 'VDN', 'padding' => 6, 'yearly' => true],
        'receipt'          => ['prefix' => 'RCP',  'padding' => 6, 'yearly' => true],
        'premium_collection' => ['prefix' => 'PCOL', 'padding' => 6, 'yearly' => true],
        'insurer_settlement' => ['prefix' => 'SETT', 'padding' => 6, 'yearly' => true],
        'expense_claim'    => ['prefix' => 'EXP',  'padding' => 6, 'yearly' => true],
        'payment_voucher'  => ['prefix' => 'PV',   'padding' => 6, 'yearly' => true],
        'petty_cash'       => ['prefix' => 'PC',   'padding' => 6, 'yearly' => true],
        'asset'            => ['prefix' => 'FA',   'padding' => 5, 'yearly' => false],
        'vat_return'       => ['prefix' => 'VATR', 'padding' => 6, 'yearly' => true],
        'revenue_schedule' => ['prefix' => 'REV',  'padding' => 6, 'yearly' => true],
        'lease'            => ['prefix' => 'LSE',  'padding' => 6, 'yearly' => true],
        'purchase_order'   => ['prefix' => 'PO',   'padding' => 6, 'yearly' => true],
        'goods_receipt'    => ['prefix' => 'GRN',  'padding' => 6, 'yearly' => true],
        'contract'         => ['prefix' => 'CON',  'padding' => 6, 'yearly' => true],
        'payroll_run'      => ['prefix' => 'PAY',  'padding' => 6, 'yearly' => true],
        'employee_loan'    => ['prefix' => 'LN',   'padding' => 6, 'yearly' => true],
    ],
];
