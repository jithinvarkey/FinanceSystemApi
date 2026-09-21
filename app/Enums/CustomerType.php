<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P4.1 — Kind of customer (a broker's policyholders/clients and counterparties).
 */
enum CustomerType: string
{
    case Individual = 'individual';
    case Corporate = 'corporate';
    case Government = 'government';
    case Broker = 'broker';
    case Other = 'other';
}
