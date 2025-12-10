<?php

namespace Efi\Gnd\Logic;

abstract class IdLookup
{
    public function __construct(
        private readonly \PDO $pdo,
    )
    {
    }

    public abstract function query(string $searchItem): ?array;

    protected function queryBase($searchItem, int $dbColType, string $startIndexCol, string $endIndexCol, string $queryTable, string $queryCol): ?array
    {
        $sql = "SELECT $startIndexCol, $endIndexCol FROM $queryTable WHERE $queryCol = :query_val";
        $sth = $this->pdo->prepare($sql);

        $queryResult = $sth->execute([':query_val' => $searchItem]);
        if ($queryResult !== true) {
            return null;
        }

        $result = $sth->fetch(\PDO::FETCH_ASSOC);
        if (!$result) {
            return null;
        }

        return [$result[$startIndexCol], $result[$endIndexCol]];
    }
}
