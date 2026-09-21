<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Exceptions\FinanceRuleException;
use App\Services\Import\Importers\CustomerImporter;
use App\Services\Import\Importers\EndorsementImporter;
use App\Services\Import\Importers\InsurerSettlementImporter;
use App\Services\Import\Importers\LineOfBusinessImporter;
use App\Services\Import\Importers\OpeningPayableImporter;
use App\Services\Import\Importers\OpeningReceivableImporter;
use App\Services\Import\Importers\OpeningTrialBalanceImporter;
use App\Services\Import\Importers\PolicyImporter;
use App\Services\Import\Importers\PremiumCollectionImporter;
use App\Services\Import\Importers\ProductImporter;
use App\Services\Import\Importers\VendorImporter;
use Illuminate\Contracts\Container\Container;

/**
 * Catalogue of importable entities, resolved from the container (so importers
 * can depend on the existing services). Ordered by their declared dependency
 * order so a "import everything" run can follow the list top to bottom.
 */
final class ImportRegistry
{
    /** @var list<class-string<EntityImporter>> */
    private const IMPORTERS = [
        LineOfBusinessImporter::class,
        CustomerImporter::class,
        VendorImporter::class,
        ProductImporter::class,
        OpeningTrialBalanceImporter::class,
        OpeningReceivableImporter::class,
        OpeningPayableImporter::class,
        PolicyImporter::class,
        EndorsementImporter::class,
        PremiumCollectionImporter::class,
        InsurerSettlementImporter::class,
    ];

    public function __construct(private readonly Container $app)
    {
    }

    /** @return list<EntityImporter> ordered by dependency order. */
    public function all(): array
    {
        $list = array_map(fn (string $class): EntityImporter => $this->app->make($class), self::IMPORTERS);
        usort($list, static fn (EntityImporter $a, EntityImporter $b): int => [$a->order(), $a->label()] <=> [$b->order(), $b->label()]);

        return $list;
    }

    public function get(string $key): EntityImporter
    {
        foreach ($this->all() as $importer) {
            if ($importer->key() === $key) {
                return $importer;
            }
        }

        throw new FinanceRuleException("Unknown import type '{$key}'.");
    }
}
