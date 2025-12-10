<?php

namespace Efi\Gnd\Logic\Lookup;

use Efi\Gnd\Enum\SequenceVersion;
use Efi\Gnd\Logic\IdLookup;

class ClusterId extends IdLookup
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
        $clusterNum = intval($queryItem);
        $table =
            $this->seqVersion === SequenceVersion::UniRef90 ? "uniref90_cluster_index" : (
            $this->seqVersion === SequenceVersion::UniRef50 ? "uniref50_cluster_index" :
            "cluster_index");
        return $this->queryBase($clusterNum, \PDO::PARAM_INT, "start_index", "end_index", $table, "cluster_num");
    }
}
