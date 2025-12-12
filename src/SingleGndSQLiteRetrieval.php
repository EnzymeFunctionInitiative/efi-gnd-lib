<?php

namespace Efi\Gnd;

use Efi\Gnd\Interface\SingleGndServiceInterface;

class SingleGndSQLiteRetrieval implements SingleGndServiceInterface
{
    public function getNeighborhoodData(string $accession, $windowSize = Util\GndConstants::DEFAULT_NEIGHBORHOOD_SIZE): ?array
    {
        return null;

    }
}
