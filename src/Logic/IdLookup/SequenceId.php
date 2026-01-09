<?php

namespace Efi\Gnd\Logic\IdLookup;

use Efi\Gnd\Enum\SequenceVersion;
use Efi\Gnd\Logic\IdLookup;

class SequenceId extends IdLookup
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
        if ($this->seqVersion === SequenceVersion::UniRef90) {
            $table = "uniref90_range";
            $idColumn = "uniref_id";
            $indexColumn = "uniref_index";
        } else if ($this->seqVersion === SequenceVersion::UniRef50) {
            $table = "uniref50_range";
            $idColumn = "uniref_id";
            $indexColumn = "uniref_index";
        } else {
            $table = "attributes";
            $idColumn = "accession";
            $indexColumn = "cluster_index";
        }
        return $this->queryBase($queryItem, \PDO::PARAM_STR, $indexColumn, $indexColumn, $table, $idColumn);
    }
}
