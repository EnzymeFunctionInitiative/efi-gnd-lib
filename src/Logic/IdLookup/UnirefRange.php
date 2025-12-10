<?php

namespace Efi\Gnd\Logic\Lookup;

use Efi\Gnd\Enum\SequenceVersion;
use Efi\Gnd\Logic\IdLookup;

class UnirefRange extends IdLookup
{
    public function __construct(
        \PDO $pdo,
        private readonly SequenceVersion $seqVersion,
    )
    {
        parent::__construct($pdo);
    }

    public function query(string $unirefIndex): ?array
    {
        $table = $this->seqVersion === SequenceVersion::UniRef50 ? "uniref50_range" : "uniref90_range";
        $column = "uniref_index";
        return $this->queryBase($unirefIndex, \PDO::PARAM_INT, "cluster_index", "cluster_index", $table, $column);
    }
}
