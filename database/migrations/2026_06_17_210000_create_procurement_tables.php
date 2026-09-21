<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 (N2) — Procurement-to-Pay. A purchase order (draft = requisition) is
 * approved, then goods/services are received against it, then it is 3-way matched
 * to the vendor invoice (ordered vs received vs invoiced). Adds an optional
 * purchase_order_id link on vendor_invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('po_number', 30)->unique();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft | approved | partially_received | received | closed | cancelled
            $table->string('notes', 250)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('description', 200);
            $table->decimal('quantity', 14, 2);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->decimal('received_qty', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('gr_number', 30)->unique();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->date('receipt_date');
            $table->string('note', 250)->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->cascadeOnDelete();
            $table->decimal('quantity', 14, 2);
            $table->timestamps();
        });

        Schema::table('vendor_invoices', function (Blueprint $table): void {
            $table->foreignId('purchase_order_id')->nullable()->after('vendor_id')->constrained('purchase_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
