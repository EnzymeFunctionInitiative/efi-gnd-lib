<?php

namespace Efi\Gnd\Logic;

use Efi\Gnd\Util\GndConstants;

class LayoutMapper
{
    // Limit tkhe scale cap by default.  The user can zoom out beyond this if they want.
    const DEFAULT_SCALE_FACTOR_CAP = 40000;
    const MAX_WIDTH_ZERO = 0.000001;

    private int $minBasePair = 999999999999;
    private int $maxBasePair = -999999999999;
    private int $maxQueryWidth = -1;

    public function __construct(
        private readonly \PDO $pdo,
        private float $scaleFactor,
    )
    {
    }

    public function computeGlobalScaleFactor(array $indices, int $queryWindow): array
    {
        $minBasePair = 999999999999;
        $maxBasePair = -999999999999;
        $maxQueryWidth = -1;

        for ($i = 0; $i < count($indices); $i++) {
            $queryParams = $this->getQueryParams($indices[$i], $minBasePair, $maxBasePair, $maxQueryWidth);

            if ($queryParams) {
                $this->getNeighborParams($queryParams['gene_key'], $queryParams['num'], $minBasePair, $maxBasePair, $queryWindow);
            }
        }

        list ($scaleFactor, $legendScale, $maxLeftOrRight, $actualMaxWidth) = $this->computeScaleFactor($minBasePair, $maxBasePair, $maxQueryWidth, self::DEFAULT_SCALE_FACTOR_CAP);

        $sfData = [
            'scale_factor' => $scaleFactor,
            'legend_scale' => $legendScale,
            'min_bp' => $minBasePair,
            'max_bp' => $maxBasePair,
            'query_width' => $maxQueryWidth,
            'actual_max_width' => $actualMaxWidth,
        ];

        return $sfData;
    }

    public function updateCoords(array $attr, bool $ignoreMaxWidth = false): int
    {
        if ($attr['rel_start_coord'] < $this->minBasePair) {
            $this->minBasePair = $attr['rel_start_coord'];
        }

        if ($attr['rel_stop_coord'] > $this->maxBasePair) {
            $this->maxBasePair = $attr['rel_stop_coord'];
        }

        $query_width = $attr['rel_stop_coord'] - $attr['rel_start_coord'];

        if (!$ignoreMaxWidth && $query_width > $this->maxQueryWidth) {
            $this->maxQueryWidth = $query_width;
        }

        return $query_width;
    }

    /**
     * Compute the relative coordinates for every gene.
     *
     * Compute the relative coordinates for every gene in the dataset.  The coordinates are
     * relative to the query gene, over the entire dataset, as opposed to absolute coordinates
     * which are the position in base pairs on the actual genome.  This modifies the data
     * structure that is created by GndReader and the Metadata (Query, Neighbor) classes by adding
     * `rel_start` and `rel_stop` keys to the attribute array for every query and neighbor gene.
     * This function also determines the min and max base pair range of the current dataset, the min
     * and max percentage of the current dataset relative to the entire dataset (using the input
     * scale factor).  If a scale factor was not provided by the browser, then one is computed and
     * returned.  A legend scale is also returned.
     *
     * @param {array} $gnds - data structure output from *GndReader* and *Metadata*
     * @return {array} min/max ranges, percentage, and scale factors
     */
    public function computeRelativeCoordinates(array $gnds): array
    {
        // Use values passed in from browser
        if ($this->scaleFactor > 0) {
            $max_width = GndConstants::MAGIC_SCALE_FACTOR / $this->scaleFactor; // scale factor is between 1 and 100 (specifying the scale factor as a percentage of the screen width = 1000AA) the data points in the file are given in bp so we x3 to get the factor in bp
            $this->maxQueryWidth = 0;
            $max_side = $max_width / 2;
            $legendScale = $max_width;
        } else {
            // Compute values based on current dataset
            list($scaleFactor, $legendScale, $max_side, $max_width) = $this->scaleFactorHelper->computeScaleFactor($this->minBasePair, $this->maxBasePair, $this->maxQueryWidth);
            $this->scaleFactor = $scaleFactor;
        }

        $min_bp = -$max_side;
        $max_bp = $max_side + $this->maxQueryWidth;

        $min_pct = 2;
        $max_pct = -2;
        for ($i = 0; $i < count($gnds); $i++) {
            $start = $gnds[$i]['attributes']['rel_start_coord'];
            $stop = $gnds[$i]['attributes']['rel_stop_coord'];
            $ac_start = 0.5;
            $ac_width = ($stop - $start) / $max_width;
            $offset = 0.5 - ($start - $min_bp) / $max_width;
            $gnds[$i]['attributes']['rel_start'] = $ac_start;
            $gnds[$i]['attributes']['rel_width'] = $ac_width;
            $acEnd = $ac_start + $ac_width;
            if ($acEnd > $max_pct)
                $max_pct = $acEnd;
            if ($ac_start < $min_pct)
                $min_pct = $ac_start;

            foreach ($gnds[$i]['neighbors'] as $idx => $data2) {
                $nb_start_bp = $gnds[$i]['neighbors'][$idx]['rel_start_coord'];
                $nb_width_bp = $gnds[$i]['neighbors'][$idx]['rel_stop_coord'] - $gnds[$i]['neighbors'][$idx]['rel_start_coord'];
                $nb_start = ($nb_start_bp - $min_bp) / $max_width;
                $nb_width = $nb_width_bp / $max_width;
                $nb_start += $offset;
                $nb_end = $nb_start + $nb_width;
                $gnds[$i]['neighbors'][$idx]['rel_start'] = $nb_start;
                $gnds[$i]['neighbors'][$idx]['rel_width'] = $nb_width;
                if ($nb_end > $max_pct)
                    $max_pct = $nb_end;
                if ($nb_start < $min_pct)
                    $min_pct = $nb_start;
            }
        }

        $output = [
            'legend_scale' => $legendScale,
            'min_pct' => $min_pct,
            'max_pct' => $max_pct,
            'min_bp' => $min_bp,
            'max_bp' => $max_bp,
            'scale_factor' => $this->scaleFactor,
            'data' => $gnds,
        ];

        return $output;
    }



    // ----- Scale factor helper -----

    /**
     * @param {int} $scaleFactorWidthCap - the default maximum max width to use for computing the scale factor.
     */
    public function computeScaleFactor(int $minBasePair, int $maxBasePair, int $maxQueryWidth, int $scaleFactorWidthCap = 0)
    {
        $maxWindowSideBasePair = (abs($maxBasePair) > abs($minBasePair)) ? abs($maxBasePair) : abs($minBasePair);
        $maxWidth = $maxWindowSideBasePair * 2 + $maxQueryWidth;
        $actualMaxWidth = $maxWidth;
        if ($scaleFactorWidthCap > 0 && $maxWidth > $scaleFactorWidthCap)
            $maxWidth = self::DEFAULT_SCALE_FACTOR_CAP;
        if ($maxWidth < self::MAX_WIDTH_ZERO)
            $maxWidth = 1;
        $scaleFactor = GndConstants::MAGIC_SCALE_FACTOR / $maxWidth;
        
        $legendScale = $maxBasePair - $minBasePair;
        
        return [$scaleFactor, $legendScale, $maxWindowSideBasePair, $actualMaxWidth];
    }




    // ----- Retrieve sequence coordinates -----

    private function getNeighborParams(string $geneKey, int $queryNum, int &$minBasePair, int &$maxBasePair, int $neighborhoodWindowSize)
    {
        $sql = 'SELECT N.rel_start AS start, N.rel_stop AS stop, N.accession AS id FROM neighbors AS N WHERE N.gene_key = :gene_key';

        $params = [':gene_key' => (int)($geneKey)];
        if ($neighborhoodWindowSize > 0) {
            $sql .= ' AND N.num >= :lower_window AND N.num <= :upper_window';
            $params[':lower_window'] = $queryNum - $neighborhoodWindowSize;
            $params[':upper_window'] = $queryNum + $neighborhoodWindowSize;
        }

        $sth = $this->pdo->prepare($sql);

        $execResult = $sth->execute($params);
        if ($execResult !== true) {
            return;
        }

        while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) {
            self::basePairCompare($row, $minBasePair, $maxBasePair);
        }
    }
    
    private static function basePairCompare(array $row, int &$minBasePair, int &$maxBasePair)
    {
        if ($row['start'] < $minBasePair)
            $minBasePair = $row['start'];
        if ($row['stop'] > $maxBasePair)
            $maxBasePair = $row['stop'];
    }

    private function getQueryParams(int $queryIndex, int &$minBasePair, int &$maxBasePair, int &$maxQueryWidth): ?array
    {
        $sql = 'SELECT A.rel_start AS start, A.rel_stop AS stop, A.sort_key AS key, A.num AS num FROM attributes AS A WHERE A.cluster_index = :seq_index';
        $sth = $this->pdo->prepare($sql);

        $execResult = $sth->execute([':seq_index' => $queryIndex]);

        if ($execResult !== true) {
            return null;
        }

        $row = $sth->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        self::basePairCompare($row, $minBasePair, $maxBasePair);

        $queryWidth = $row['stop'] - $row['start'];
        if ($queryWidth > $maxQueryWidth) {
            $maxQueryWidth = $queryWidth;
        }

        return ['gene_key' => $row['key'], 'num' => $row['num']];
    }
}
