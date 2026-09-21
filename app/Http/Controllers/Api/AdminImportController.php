<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Import\EntityImporter;
use App\Services\Import\ExcelImportService;
use App\Services\Import\ImportRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P-IMP — Admin bulk data import. Per-entity Excel upload with a dry-run
 * preview (row-by-row validation) before an atomic commit. Reads are gated on
 * general-ledger.view; commits (which write) on general-ledger.post.
 */
final class AdminImportController extends Controller
{
    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly ExcelImportService $excel,
    ) {
    }

    /** Catalogue of importable entities, grouped and ordered. */
    public function index(): JsonResponse
    {
        $this->authorize('general-ledger.view');

        $data = array_map(fn (EntityImporter $i): array => [
            'key' => $i->key(),
            'label' => $i->label(),
            'group' => $i->group(),
            'order' => $i->order(),
            'columns' => $i->columns(),
        ], $this->registry->all());

        return response()->json(['data' => $data]);
    }

    /** Download a header-only .xlsx template for one entity. */
    public function template(string $key): StreamedResponse
    {
        $this->authorize('general-ledger.view');
        $importer = $this->registry->get($key);
        $binary = $this->excel->template($importer);

        return response()->streamDownload(
            fn () => print($binary),
            "{$key}-template.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** Validate an uploaded file without writing (dry run). */
    public function preview(Request $request, string $key): JsonResponse
    {
        $this->authorize('general-ledger.view');
        $importer = $this->registry->get($key);
        $file = $this->validatedFile($request);

        $rows = $this->excel->parse($file, $importer);

        return response()->json(['data' => $this->excel->dryRun($importer, $rows)]);
    }

    /** Commit the file atomically (all-or-nothing). */
    public function commit(Request $request, string $key): JsonResponse
    {
        $this->authorize('general-ledger.post');
        $importer = $this->registry->get($key);
        $file = $this->validatedFile($request);

        $rows = $this->excel->parse($file, $importer);
        $result = $this->excel->commit($importer, $rows, (int) $request->user()->id);

        return response()->json(['data' => $result]);
    }

    private function validatedFile(Request $request): \Illuminate\Http\UploadedFile
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        return $request->file('file');
    }
}
