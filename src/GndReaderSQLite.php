<?php

namespace Efi\Gnd\Logic;

use Efi\Gnd\Dto\GndMetadata;
use Efi\Gnd\Enum\SequenceVersion;
use Efi\Gnd\Interface\GndReaderInterface;
use \PDO;
use \PDOException;

class GndReaderSQLite implements GndReaderInterface
{
    private ?SequenceVersion $sequenceVersion = null;

    private ?GndMetadata $metadata = null;

    public function __construct(
        private readonly PDO $pdo,
    )
    {
    }

    public function getSequenceVersion(): SequenceVersion
    {
        if ($this->sequenceVersion !== null) {
            return $this->sequenceVersion;
        }
        $this->sequenceVersion = $this->checkForSequenceVersion();
        return $this->sequenceVersion;
    }

    public function getSearchExtent(array $searchItems, GndQueryParams $params): array
    {
        $data = [
            'message' => '',
            'error' => false,
            'eod' => false, // End of data
        ];

        $stats = $this->computeSearchStats($searchItems, $params);
        $data['stats'] = $stats;

        return $data;
    }

    public function retrieveRanges(string $rangeParameter, GndQueryParams $params): array
    {
        $ranges = $this->parseRangeParameter($rangeParameter);
        return $this->retrieveRange($ranges, $params);
    }

    /**
     * @param {array} $ranges - 1x2 array (e.g. [ [start_idx, end_idx] ])
     */
    private function retrieveRange(array $ranges, GndQueryParams $params): array
    {
        $rangeIndices = $this->flattenRanges($ranges);

        if ($params->sequenceVersion !== SequenceVersion::UniProt) {
            $indices = $this->getUnirefIndices($rangeIndices, $params);
        } else {
            $indices = $rangeIndices;
        }

        $gnds = [];

        $coordHelper = $this->getLayoutMapper();

		$attrParser = new Logic\SequenceMetadataParser();

        foreach ($indices as $index) {
            $queryRow = $this->getQueryRow($index);
            if (!$queryRow) {
                continue;
            }

            $queryData = $attrParser->parseAttributes($queryRow);

            $coordHelper->updateCoords($queryData);

            $neighborRows = $this->getNeighborRows($queryRow['sort_key'], $queryRow['num'], $params->window);
            $neighborData = [];
            foreach ($neighborRows as $neighborRow) {
                $nb = $attrParser->parseAttributes($neighborRow);
                $coordHelper->updateCoords($nb, true);
                $neighborData[] = $nb;
            }

            $gnds[] = ['attributes' => $queryData, 'neighbors' => $neighborData];
        }

        // This contains  statistics and factors that are also returned.  The GNDs are also updated
        // in here and saved into the returned array.
        $output = $coordHelper->computeRelativeCoordinates($gnds);

        $output['eod'] = count($indices) == 0;
        $output['counts'] = [ 'max' => count($indices), 'invalid' => [], 'displayed' => 0 ];

        return $output;
    }

    public function getMetadata(): GndMetadata
    {
        if ($this->metadata === null) {
            $sql = 'SELECT COUNT(*) AS num_clusters FROM cluster_index';
            $result = $this->getPdo()->query($sql);
            $row = $result->fetch(\PDO::FETCH_ASSOC);
            $numClusters = $row['num_clusters'] ?? 0;

            $sql = 'SELECT cluster_num FROM cluster_index ORDER BY cluster_num ASC LIMIT 1';
            $result = $this->getPdo()->query($sql);
            $row = $result->fetch(\PDO::FETCH_ASSOC);
            $firstCluster = $row['cluster_num'] ?? null;

            $sql = 'SELECT * FROM metadata';
            $result = $this->getPdo()->query($sql);
            $row = $result->fetch(\PDO::FETCH_ASSOC);
            $jobName = $row['name'] ?? $row['type'] ?? 'A Job';

            $sequenceVersion = $this->getSequenceVersion();
            $this->metadata = new GndMetadata(
                $jobName,
                $row['cooccurrence'],
                $row['neighborhood_size'],
                $sequenceVersion,
                $numClusters,
                $firstCluster,
            );
        }

        return $this->metadata;
    }





    // ----- Helpers ------

    private function tableExists(string $tableName): bool
    {
        $pdo = $this->getPdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // SQLite uses a specific master table
        if ($driver === 'sqlite') {
            $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name = :table";
        } 
        // MySQL, PostgreSQL, and SQL Server use information_schema
        else {
            $sql = "SELECT table_name FROM information_schema.tables WHERE table_name = :table";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['table' => $tableName]);

        return $stmt->fetch() !== false;
    }

    private function checkForSequenceVersion(): SequenceVersion
    {
        if ($this->tableExists('uniref50_index')) {
            return SequenceVersion::UniRef50;
        }

        if ($this->tableExists('uniref90_index')) {
            return SequenceVersion::UniRef90;
        }

        return SequenceVersion::UniProt;
    }

    private function getLayoutMapper(): Logic\LayoutMapper
    {
        if ($this->layoutMapper !== null) {
            return $this->layoutMapper;
        }
        $this->layoutMapper = new Logic\LayoutMapper($this->getPdo());
        return $this->layoutMapper;
    }

    private function getPdo(): PDO
    {
        return $this->pdo;
    }




    // ----- Range helpers -----

    private function getQueryRow(int $index): ?array
    {
        $sql = 'SELECT * FROM attributes WHERE cluster_index = :id';
        $sth = $this->getPdo()->prepare($sql);

        $queryResult = $sth->execute([':id' => $index]);
        if ($queryResult !== true) {
            return null;
        }

        $row = $sth->fetch(\PDO::FETCH_ASSOC);

		return $row;
    }

    private function getNeighborRows($geneKey, $queryNum, int $neighborhoodWindowSize): array
    {
        $sql = 'SELECT * FROM neighbors WHERE gene_key = :gene_key';

        $params = [':gene_key' => $geneKey];
        if ($neighborhoodWindowSize > 0) {
            $sql .= ' AND num >= :lower_window AND num <= :upper_window';
            $params[':lower_window'] = $queryNum - $neighborhoodWindowSize;
            $params[':upper_window'] = $queryNum + $neighborhoodWindowSize;
        }

        $sth = $this->getPdo()->prepare($sql);

        $execResult = $sth->execute($params);

        if ($execResult !== true) {
            return [];
        }

        $rows = [];
        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function parseRangeParameter(string $rangeParameter): array
    {
        $ranges = [];
        $subRanges = preg_split('/,+/', $rangeParameter);
        foreach ($subRanges as $range) {
            $parts = explode('-', $range);
            if (count($parts) > 1 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
                $ranges[] = [intval($parts[0]), intval($parts[1])];
            } else if (count($parts) > 0 && ctype_digit($parts[0])) {
                $ranges[] = [intval($parts[0]), intval($parts[0])];
            }
        }
        return $ranges;
    }

    private function getUnirefIndices(array $rangeIndices, GndQueryParams $params): array
    {
        $indices = [];

        if ($params->unirefId) {
            $indexLookup = new \Efi\GndViewerBundle\Stats\Lookup\UnirefIdIndex($this->getPdo(), $params->sequenceVersion);
        } else {
            $indexLookup = new \Efi\GndViewerBundle\Stats\Lookup\UnirefRange($this->getPdo(), $params->sequenceVersion);
        }

        foreach ($rangeIndices as $unirefIndexId) {
            $memberIndex = $indexLookup->query($unirefIndexId);
            if (count($memberIndex) > 0) {
                $indices[] = $memberIndex[0];
            }
        }

        return $indices;
    }



    // ----- Statistics helpers used to determine extent of search -----

    private function computeSearchStats(array $searchItems, GndQueryParams $params): array
    {
        $timeStart = microtime(true);

        $indexRanges = $this->getSearchIndexRanges($searchItems, $params);

        $allIndices = $this->flattenRanges($indexRanges);
        $indicesForScaleFactor = $this->getScaleFactorIndices($allIndices);

        $stats = $this->getLayoutMapper()->computeGlobalScaleFactor($indicesForScaleFactor, $params->window);

        $totalTime = microtime(true) - $timeStart;

        $stats['total_records'] = count($allIndices);
        $stats['num_checked'] = count($indicesForScaleFactor);
        $stats['index_range'] = $indexRanges;
        $stats['total_time'] = $totalTime;

        return $stats;
    }

    /**
     * Get a subset of all of the indices for the purpose of computing a scale factor.
     */
    private function getScaleFactorIndices(array $allIndices): array
    {
        $numIndices = count($allIndices);

        // Get first 100
        $indices = array_slice($allIndices, 0, 100);

        if ($numIndices > 100) {
            // Now look at a random selection of the remaining indices
            $extraToCheck = max(1, round(intval($numIndices / 200) / 10) * 10);
            for ($i = 100; $i < $numIndices; $i++) {
                if (rand(1, $numIndices) % $extraToCheck == 0) {
                    array_push($indices, $allIndices[$i]);
                }
            }
        }

        return $indices;
    }




    // ----- Helpers to assist in finding ranges of IDs and clusters -----

    /**
     * Flatten the 2-d array of index ranges.
     *
     * Return an array of indices into the attributes table for each range in the input.  This
     * flattens and expands the output of the `getSearchIndexRanges` table.  For example, if the
     * input is the example given in `getSearchIndexRanges`, the output of this function is
     * [0, 1, 2, ..., 41, 42, 170, 67, 68, 69, ... 90, 91, 256]
     */
    private function flattenRanges(array $indexRanges): array
    {
        $idx = [];
        for ($r = 0; $r < count($indexRanges); $r++) {
            $idx = array_merge($idx, range($indexRanges[$r][0], $indexRanges[$r][1]));
        }
        return $idx;
    }

    /**
     * Get the start and stop attribute table indexes for the query inputs.
     *
     * Compute and return the start and indexes for each query item into the master attributes
     * table.  For example, given a query of '1', 'B0SS77', '2', 'A0A077' with cluster '1'
     * containing sequences 0-42 in the attributes table and cluster '2' containing
     * sequences 67-91, and 'B0SS77' stored in row 170 and 'A0A077' stored in row 256, this
     * array looks like:
     *   [
     *     [0, 42],
     *     [170, 170],
     *     [67, 91],
     *     [256, 256],
     *   ]
     * (The index data comes from the cluster_index table.)
     *
     * Also pass in the user-requested sequence version (already validated).
     */
    private function getSearchIndexRanges(array $searchItems, GndQueryParams $params): array
    {
        // This handles the case when the user wants to look at the contents of a UniRef cluster
        if ($params->unirefId) {
            $unirefIdLookup = new \Efi\GndViewerBundle\Stats\Lookup\UnirefIdSearch($this->getPdo(), $params->sequenceVersion);
            $indexRanges = $unirefIdLookup->query($params->unirefId);
            return [$indexRanges];
        }

        $clusterIdLookup = new \Efi\GndViewerBundle\Stats\Lookup\ClusterId($this->getPdo(), $params->sequenceVersion);
        $sequenceIdLookup = new \Efi\GndViewerBundle\Stats\Lookup\SequenceId($this->getPdo());

        $indexRanges = [];

        foreach ($searchItems as $item) {
            $indexResult = null;
            if (ctype_digit($item)) {
                $indexResult = $clusterIdLookup->query($item);
            } else {
                $indexResult = $sequenceIdLookup->query($item);
            }

            if ($indexResult) {
                array_push($indexRanges, $indexResult);
            }
        }

        return $indexRanges;
    }
}
