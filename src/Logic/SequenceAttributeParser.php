<?php

namespace Efi\Gnd\Logic;

class SequenceAttributeParser
{
    /**
     * Parse values from the database row.
     *
     * Parse data from the database row and return it in a way that can be returned to the browser.
     *
     * @param {array} $row - database row
     * @return {array} associative array
     */
    public function parseAttributes(array $row, bool $isQuery): array
    {
        $attr = array();
        $attr['accession'] = $row['accession'];
        $attr['id'] = $row['id'];
        $attr['num'] = $row['num'];
        $attr['pfam'] = explode("-", $row['family']);
        $attr['start'] = $row['start'];
        $attr['stop'] = $row['stop'];
        $attr['rel_start_coord'] = $row['rel_start'];
        $attr['rel_stop_coord'] = $row['rel_stop'];
        $attr['direction'] = $row['direction'];
        $attr['type'] = $row['type'];
        $attr['seq_len'] = $row['seq_len'];
        $attr['anno_status'] = $row['anno_status'];
        $attr['desc'] = $row['desc'];

        $this->setFamilyAttributes($attr, $row, $isQuery);

        $this->setColorAttributes($attr, $row)

        if ($isQuery) {
            $this->setQueryAttributes($attr, $row);
        }

        return $attr;
    }

    private function setColorAttributes(array &$attr, array $row)
    {
        $familyCount = count($attr['pfam']);
        if (array_key_exists("color", $row)) {
            $attr['color'] = explode(",", $row['color']);
        }

        if (count($attr['color']) < $familyCount) {
            if (count($attr['color']) > 0)
                $attr['color'] = array_fill(0, $familyCount, $attr['color'][0]);
            else
                $attr['color'] = array_fill(0, $familyCount, "grey");
        }
    }

    private function setQueryAttributes(array &$attr, array $row)
    {
        if (array_key_exists("sort_order", $row))
            $attr['sort_order'] = $row['sort_order'];
        else
            $attr['sort_order'] = -1;

        if (array_key_exists("is_bound", $row))
            $attr['is_bound'] = $row['is_bound'];
        else
            $attr['is_bound'] = 0;

        $attr['pid'] = -1;

        if (array_key_exists('evalue', $row) && $row['evalue'] !== NULL)
            $attr['evalue'] = $row['evalue'];
        else if (array_key_exists('cluster_num', $row))
            $attr['cluster_num'] = $row['cluster_num'];

        if (isset($row['uniref50_size']))
            $attr['uniref50_size'] = $row['uniref50_size'];
        if (isset($row['uniref90_size']))
            $attr['uniref90_size'] = $row['uniref90_size'];

        $attr['taxon_id'] = $row['taxon_id'];
        $attr['strain'] = $row['strain'];
        $attr['organism'] = rtrim($row['organism']);
        if (strlen($attr['organism']) > 0 && substr_compare($attr['organism'], ".", -1) === 0)
            $attr['organism'] = substr($attr['organism'], 0, strlen($attr['organism'])-1);
    }

    private function setFamilyAttributes(array &$attr, array $row, bool $isQuery)
    {
        if (isset($row['ipro_family']))
            $attr['ipro_family'] = explode("-", $row['ipro_family']);
        else
            $attr['ipro_family'] = array();

        if ($isQuery)
            $attr['ipro_family'][0] = "none-query";

        $familyDesc = explode(";", $row['family_desc']);
        if (count($familyDesc) == 1) {
            $familyDesc = explode("-", $row['family_desc']);
            if ($familyDesc[0] == "" && $isQuery)
                $familyDesc[0] = "Query without family";
        }

        $attr['pfam_desc'] = $familyDesc;
        if (count($attr['pfam_desc']) < $familyCount) {
            if (count($attr['pfam_desc']) > 0)
                $attr['pfam_desc'] = array_fill(0, $familyCount, $attr['pfam_desc'][0]);
            else
                $attr['pfam_desc'] = array_fill(0, $familyCount, "none");
        }

        $iproFamilyCount = isset($attr['ipro_family']) ? count($attr['ipro_family']) : 0;
        $iproFamilyDesc = isset($row['ipro_family_desc']) ? explode(";", $row['ipro_family_desc']) : array();
        if (count($iproFamilyDesc) == 1) {
            $iproFamilyDesc = explode("-", $row['ipro_family_desc']);
            if ($iproFamilyDesc[0] == "" && $isQuery)
                $iproFamilyDesc[0] = "Query without family";
        }
        $attr['ipro_family_desc'] = $iproFamilyDesc;
        if (count($attr['ipro_family_desc']) < $iproFamilyCount) {
            if (count($attr['ipro_family_desc']) > 0)
                $attr['ipro_family_desc'] = array_fill(0, $iproFamilyCount, $attr['ipro_family_desc'][0]);
            else
                $attr['ipro_family_desc'] = array_fill(0, $iproFamilyCount, "none");
        }

        // Rename
        $attr['interpro'] = $attr['ipro_family'];
        $attr['interpro_desc'] = $attr['ipro_family_desc'];
    }
}
