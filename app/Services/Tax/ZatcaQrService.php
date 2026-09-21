<?php

declare(strict_types=1);

namespace App\Services\Tax;

use App\Models\CompanySetting;
use App\Models\CustomerInvoice;
use Illuminate\Support\Carbon;

/**
 * F16 — ZATCA (Fatoorah) Phase-1 e-invoicing QR.
 *
 * Builds the mandatory TLV (tag-length-value) payload, Base64-encoded, that
 * Phase-1 simplified tax invoices must carry as a QR code. The five mandatory
 * tags (ZATCA QR spec):
 *   1 Seller name              2 VAT registration number
 *   3 Invoice timestamp (ISO)  4 Invoice total (with VAT)
 *   5 VAT total
 *
 * Phase-2 (cryptographic stamp, UBL 2.1 XML, ZATCA clearance API) requires
 * ZATCA onboarding/CSIDs and is out of scope here.
 */
final class ZatcaQrService
{
    /**
     * @return array{
     *     seller_name: string, vat_number: string, timestamp: string,
     *     invoice_total: string, vat_total: string, tlv_base64: string
     * }
     */
    public function forCustomerInvoice(CustomerInvoice $invoice): array
    {
        $seller = CompanySetting::current();

        $timestamp = ($invoice->posted_at ?? Carbon::parse((string) $invoice->invoice_date))
            ->utc()->format('Y-m-d\TH:i:s\Z');
        $invoiceTotal = number_format((float) $invoice->total_amount, 2, '.', '');
        $vatTotal = number_format((float) $invoice->tax_amount, 2, '.', '');

        $tlv =
            $this->tag(1, (string) $seller->company_name).
            $this->tag(2, (string) $seller->vat_number).
            $this->tag(3, $timestamp).
            $this->tag(4, $invoiceTotal).
            $this->tag(5, $vatTotal);

        return [
            'seller_name' => (string) $seller->company_name,
            'vat_number' => (string) $seller->vat_number,
            'timestamp' => $timestamp,
            'invoice_total' => $invoiceTotal,
            'vat_total' => $vatTotal,
            'tlv_base64' => base64_encode($tlv),
        ];
    }

    /**
     * One TLV field: 1 byte tag + 1 byte length (UTF-8 byte length) + value.
     * Values here are always < 255 bytes, so single-byte length is sufficient.
     */
    private function tag(int $tag, string $value): string
    {
        return chr($tag).chr(strlen($value)).$value;
    }
}
