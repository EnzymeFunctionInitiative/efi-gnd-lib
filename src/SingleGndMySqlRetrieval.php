<?php
/**
 * GND / Neighborhood Data Generator
 *
 * Replicates the logic from gnd.pl, Neighborhood.pm, and Annotations.pm
 * to generate the specific JSON structure required for the EFI GNT viewer.
 */

namespace Efi\Gnd;

use \PDO;
use Efi\Gnd\Dto\SingleGndMySqlRetrievalParams;

class SingleGndMySqlRetrieval
{
    private PDO $pdo;
    private string $warning = "";

    public function __construct(SingleGndMySqlRetrievalParams $params)
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $this->pdo = new PDO($params->getDsn(), $params->username, $params->password, $options);
        } catch (\PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    public function getError(): string
    {
        return $this->error;
    }

    /**
     * Main entry point: Generates the JSON for a specific accession.
     */
    public function getNeighborhoodData(string $accession, $windowSize = Util\GndConstants::DEFAULT_NEIGHBORHOOD_SIZE): ?array
    {
        
        $queryData = $this->processQueryId($accession, $windowSize);

        if (!$queryData) {
            return null;
        }

        $neighbors = $this->fetchNeighbors($queryData, $windowSize);

        // Annotate Data (Organisms, Descriptions, Families)
        $finalStructure = [
            "attributes" => $this->formatNode($queryData['attributes'], true),
            "neighbors" => []
        ];

        foreach ($neighbors as $neighbor) {
            // Skip the query itself if it appears in neighbors list
            if ($neighbor['AC'] === $accession)
                continue;
            $finalStructure['neighbors'][] = $this->formatNode($neighbor, false);
        }

        return $finalStructure;
    }

    /**
     * Replicates Neighborhood.pm -> processQueryId
     */
    private function processQueryId(string $accession, int $windowSize): ?array
    {
        $emblId = $this->getEmblId($accession);

        if (!$emblId) {
            $this->warning = "No match in the ENA table for $accession";
            return null;
        }

        // Get Query Details
        $sql = "SELECT 
                    ena.ID, ena.AC, ena.NUM, ena.TYPE, ena.DIRECTION, ena.start, ena.stop,
                    GROUP_CONCAT(DISTINCT PFAM.id) AS pfam_fam,
                    GROUP_CONCAT(DISTINCT I.id) AS ipro_fam
                FROM ena 
                LEFT JOIN PFAM ON ena.AC = PFAM.accession
                LEFT JOIN INTERPRO AS I ON ena.AC = I.accession
                WHERE ena.ID = ? AND ena.AC = ? 
                GROUP BY ena.AC LIMIT 1";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$emblId, $accession]);
        $row = $stmt->fetch();

        if (!$row)
            return null;

        // Calculate Genome Boundaries (Max NUM/Coord)
        $maxSql = "SELECT NUM, stop FROM ena WHERE ID = ? ORDER BY NUM DESC LIMIT 1";
        $maxStmt = $this->pdo->prepare($maxSql);
        $maxStmt->execute([$emblId]);
        $maxRow = $maxStmt->fetch();

        $maxNum = $maxRow['NUM'];
        $maxCoord = $maxRow['stop'];

        // Calculate Windows
        $queryNum = $row['NUM'];
        $lowWindow = $queryNum - $windowSize;
        $highWindow = $queryNum + $windowSize;

        // Check bounds (Circular logic flag)
        $isBound = 0;
        if ($lowWindow < 1) $isBound = 1; // Left bound cross
        if ($highWindow > $maxNum) $isBound = ($isBound | 2); // Right bound cross

        $attributes = $row;
        $attributes['max_num'] = $maxNum;
        $attributes['max_coord'] = $maxCoord;
        $attributes['low_window'] = $lowWindow;
        $attributes['high_window'] = $highWindow;
        $attributes['is_bound'] = $isBound;
        $attributes['query_start'] = $row['start']; // Store original query start for rel calc
        
        return ['attributes' => $attributes, 'embl_id' => $emblId];
    }

    /**
     * Replicates Neighborhood.pm -> initializeNeighborDbQuery & processNeighbor
     * AND adds dynamic scaling so coordinates fit strictly between 0 and 1.
     */
    private function fetchNeighbors(array &$queryData, int $windowSize): array
    {
        $attr = $queryData['attributes'];
        $emblId = $queryData['embl_id'];
        
        $sql = "SELECT 
                    ena.ID, ena.AC, ena.NUM, ena.TYPE, ena.DIRECTION, ena.start, ena.stop,
                    GROUP_CONCAT(DISTINCT PFAM.id) AS pfam_fam,
                    GROUP_CONCAT(DISTINCT I.id) AS ipro_fam
                FROM ena 
                LEFT JOIN PFAM ON ena.AC = PFAM.accession
                LEFT JOIN INTERPRO AS I ON ena.AC = I.accession
                WHERE ena.ID = ? ";

        $params = [$emblId];

        // Circular Logic (Replicates getCircularPos)
        if ($attr['TYPE'] == 0) { // Circular
            $mainClause = "(ena.NUM >= ? AND ena.NUM <= ?)";
            $params[] = $attr['low_window'];
            $params[] = $attr['high_window'];
            
            $orClauses = [];
            if ($attr['low_window'] < 1) {
                $circHigh = $attr['max_num'] + $attr['low_window'];
                $orClauses[] = "ena.NUM >= $circHigh";
            }
            if ($attr['high_window'] > $attr['max_num']) {
                $circLow = $attr['high_window'] - $attr['max_num'];
                $orClauses[] = "ena.NUM <= $circLow";
            }

            if (!empty($orClauses)) {
                $subClause = implode(" OR ", $orClauses);
                $sql .= " AND ($mainClause OR $subClause)";
            } else {
                 $sql .= " AND $mainClause";
            }
        } else {
            // Linear
            $sql .= " AND ena.NUM >= ? AND ena.NUM <= ?";
            $params[] = $attr['low_window'];
            $params[] = $attr['high_window'];
        }

        $sql .= " GROUP BY ena.AC ORDER BY ena.NUM";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $neighbors = $stmt->fetchAll();

        // --- DYNAMIC SCALING LOGIC ---

        // 1. Calculate Integer Coordinates (BP) and find the maximum extent from 0
        $maxExtent = 0; 

        foreach ($neighbors as &$nb) {
            $this->calculateRelativeCoords($nb, $attr); 
            
            // Find the furthest edge (start or stop) from the query start (0)
            // abs() is needed because neighbors to the left have negative coords
            $maxExtent = max($maxExtent, abs($nb['rel_start_coord']), abs($nb['rel_stop_coord']));
        }
        unset($nb); // Break reference

        // 2. Define Total Span
        // To force the Query Start (0) to be at 0.5 (center), 
        // the total viewport must be 2 * maxExtent.
        // This ensures the range [-maxExtent, +maxExtent] maps to [0.0, 1.0].
        $totalSpan = ($maxExtent > 0) ? (2 * $maxExtent) : 1000;

        // 3. Apply Scaling to Neighbors
        foreach ($neighbors as &$nb) {
            $nb['rel_start_pct'] = 0.5 + ($nb['rel_start_coord'] / $totalSpan);
            $nb['rel_width_pct'] = $nb['seq_len_bp'] / $totalSpan;
        }
        unset($nb);

        // 4. Apply Scaling to the Query Data itself (for the "Query" node)
        // We must calculate its relative coords first (which will be 0)
        $this->calculateRelativeCoords($queryData['attributes'], $attr);
        $queryData['attributes']['rel_start_pct'] = 0.5 + ($queryData['attributes']['rel_start_coord'] / $totalSpan);
        $queryData['attributes']['rel_width_pct'] = $queryData['attributes']['seq_len_bp'] / $totalSpan;

        return $neighbors;
    }

    /**
     * Replicates populateNeighborPositionData math
     * Calculates integer BP coordinates relative to the query start.
     */
    private function calculateRelativeCoords(array &$nb, array $queryAttr)
    {
        $nbStart = (int)$nb['start'];
        $nbStop = (int)$nb['stop'];
        $seqLen = abs($nbStop - $nbStart) + 1;
        $neighNum = $nb['NUM'];

        // Distance and Relative Start Calculation (Circular Aware)
        $distance = 0;
        $relNbStart = 0;

        if ($neighNum > $queryAttr['high_window'] && isset($queryAttr['circ_high'])) {
             $distance = $neighNum - $queryAttr['NUM'] - $queryAttr['max_num'];
             $relNbStart = $nbStart - $queryAttr['max_coord'];
        } elseif ($neighNum < $queryAttr['low_window'] && isset($queryAttr['circ_low'])) {
             $distance = $neighNum - $queryAttr['NUM'] + $queryAttr['max_num'];
             $relNbStart = $queryAttr['max_coord'] + $nbStart;
        } else {
            $distance = $neighNum - $queryAttr['NUM'];
            $relNbStart = $nbStart;
        }

        // Relative to Query Start
        // If this is the query itself, relNbStart == query_start, so result is 0.
        $relNbStart = (int)($relNbStart - $queryAttr['query_start']);
        $relNbStop = (int)($relNbStart + $seqLen);

        $nb['rel_start_coord'] = $relNbStart;
        $nb['rel_stop_coord'] = $relNbStop;
        $nb['seq_len_bp'] = $seqLen;
        $nb['seq_len_aa'] = intval($seqLen / 3); 
        $nb['distance'] = $distance;
    }

    /**
     * Replicates Annotations.pm logic and merges into the complex JSON object structure
     */
    private function formatNode(array $row, bool $isQuery): array
    {
        // 1. Fetch Annotations (Taxonomy, Desc)
        $annoSql = "SELECT * FROM annotations WHERE accession = ?";
        $stmt = $this->pdo->prepare($annoSql);
        $stmt->execute([$row['AC']]);
        $anno = $stmt->fetch();

        // Decode metadata if present (JSON or serialized in DB? schema says TEXT)
        // Perl decode_meta_struct implies a custom format, assuming simple JSON here or direct mapping
        $metadata = []; // Default
        // In a real scenario, parse $anno['metadata'] here.

        // 2. Process Families (Pfam/InterPro)
        $pfams = array_filter(explode(',', $row['pfam_fam'] ?? ''));
        $ipros = array_filter(explode(',', $row['ipro_fam'] ?? ''));

        // Fetch Descriptions for Families
        $pfamDescs = $this->getFamilyDescriptions($pfams);
        $iproDescs = $this->getFamilyDescriptions($ipros);

        // Merge formatted strings "ID (Description)"
        $pfamMerged = [];
        foreach ($pfams as $p) $pfamMerged[] = "$p (" . ($pfamDescs[$p] ?? 'Unknown') . ")";
        
        $iproMerged = [];
        foreach ($ipros as $i) $iproMerged[] = "$i (" . ($iproDescs[$i] ?? 'Unknown') . ")";

        // 3. Construct the "Attr" object (Raw/Backend Data)
        $attr = [
            "accession" => $row['AC'],
            "id" => $row['ID'],
            "num" => (int)$row['NUM'],
            "pfam" => array_values($pfams),
            "ipro_family" => array_values($ipros),
            "start" => (int)$row['start'],
            "stop" => (int)$row['stop'],
            "rel_start_coord" => $row['rel_start_coord'],
            "rel_stop_coord" => $row['rel_stop_coord'],
            "strain" => "", // Not in schema provided
            "direction" => ($row['DIRECTION'] == 0) ? "complement" : "normal",
            "type" => ($row['TYPE'] == 0) ? "circular" : "linear",
            "seq_len" => (int)$row['seq_len_aa'],
            "organism" => $anno['organism'] ?? "Unknown Organism", // Derived from metadata usually
            "taxon_id" => (int)($anno['taxonomy_id'] ?? 0),
            "anno_status" => (int)($anno['swissprot_status'] ?? 0),
            "desc" => "Description placeholder", // Would come from metadata
            "pfam_desc" => array_values($pfamDescs),
            "ipro_family_desc" => array_values($iproDescs),
            "interpro" => array_values($ipros),
            "interpro_desc" => array_values($iproDescs),
            // Color generation (Hashed from accession or family for consistent visuals)
            "color" => [$this->generateColor($pfams[0] ?? $row['AC'])], 
            "is_bound" => 0, // Simplified
            "rel_start" => $row['rel_start_pct'], // Float for UI
            "rel_width" => $row['rel_width_pct'], // Float for UI
        ];

        if ($isQuery) {
            $attr['sort_order'] = 1; // Example default
            $attr['pid'] = -1;
        }

        return $attr;

        // 4. Construct the Root Node Object (UI formatting)
        //$node = [
        //    "attributes" => $attr,
        //    "Id" => $row['AC'],
        //    "Organism" => $attr['organism'],
        //    "TaxonId" => $attr['taxon_id'],
        //    "EnaId" => $row['ID'],
        //    "Evalue" => "",
        //    "RelStart" => $row['rel_start_pct'], // Float for UI
        //    "RelWidth" => $row['rel_width_pct'], // Float for UI
        //    "IsComplement" => ($row['DIRECTION'] == 0),
        //    "IsSwissProt" => (bool)($anno['swissprot_status'] ?? false),
        //    "SequenceLength" => $attr['seq_len'],
        //    "Description" => $attr['desc'],
        //    "NumUniref50Ids" => 0, // Placeholder, requires uniref table join
        //    "NumUniref90Ids" => 0,
        //    "Pfam" => $attr['pfam'],
        //    "InterPro" => $attr['interpro'],
        //    "PfamMerged" => $pfamMerged,
        //    "InterProMerged" => $iproMerged,
        //    "Colors" => $attr['color'],
        //    "IsQuery" => $isQuery
        //];

        //return $node;
    }

    private function getFamilyDescriptions(array $ids): array
    {
        if (empty($ids))
            return [];
        
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $sql = "SELECT family, short_name FROM family_info WHERE family IN ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);
        
        $result = [];
        while ($row = $stmt->fetch()) {
            $result[$row['family']] = $row['short_name'];
        }
        return $result;
    }

    private function getEmblId(string $accession): ?string
    {
        // Simple ENA lookup
        $sql = "SELECT ID FROM ena WHERE AC = ? ORDER BY TYPE LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$accession]);
        $row = $stmt->fetch();
        return $row['ID'] ?? null;
    }

    // Utility to generate a consistent hex color from a string
    private function generateColor(string $str): string
    {
        $hash = md5($str);
        return "#" . substr($hash, 0, 6);
    }
}
