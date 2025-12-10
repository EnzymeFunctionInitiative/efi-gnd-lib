<?php

namespace Efi\Gnd\Logic\Lookup;

use Efi\Gnd\Enum\SequenceVersion;
use Efi\Gnd\Logic\IdLookup;

class UnirefIdIndex extends IdLookup
{
    public function __construct(
        \PDO $pdo,
        private readonly SequenceVersion $seqVersion,
    )
    {
        parent::__construct($pdo);
    }

    public function query(string $queryItem): ?array
    {
        $table = $this->seqVersion === SequenceVersion::UniRef50 ? "uniref50_index" : "uniref90_index";
        $column = "member_index";
        return $this->queryBase($queryItem, \PDO::PARAM_STR, "cluster_index", "cluster_index", $table, $column);
    }
}
