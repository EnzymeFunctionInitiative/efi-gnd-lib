<?php

namespace Efi\Gnd\Logic\Lookup;

use Efi\Gnd\Enum\SequenceVersion;
use Efi\Gnd\Logic\IdLookup;

class UnirefIdSearch extends IdLookup
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
        $table = $this->seqVersion === SequenceVersion::UniRef50 ? "uniref50_range" : "uniref90_range";
        $column = "uniref_id";
        return $this->queryBase($queryItem, \PDO::PARAM_STR, "start_index", "end_index", $table, $column);
    }
}
