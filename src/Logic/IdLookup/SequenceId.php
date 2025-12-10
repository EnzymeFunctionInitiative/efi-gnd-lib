<?php

namespace Efi\Gnd\Logic\Lookup;

use Efi\Gnd\Logic\IdLookup;

class SequenceId extends IdLookup
{
    public function __construct(
        \PDO $pdo,
    )
    {
        parent::__construct($pdo);
    }

    public function query(string $queryItem): ?array
    {
        return $this->queryBase($queryItem, \PDO::PARAM_STR, "cluster_index", "cluster_index", "attributes", "accession");
    }
}
