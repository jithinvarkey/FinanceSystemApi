<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * P3.1 — Classification of a vendor. "Insurer" matters for an insurance broker
 * (premium payable), distinct from ordinary suppliers and service providers.
 */
enum VendorType: string
{
    case Supplier = 'supplier';
    case ServiceProvider = 'service_provider';
    case Insurer = 'insurer';
    case Contractor = 'contractor';
    case Other = 'other';
}
