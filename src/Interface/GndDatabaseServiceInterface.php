<?php

namespace Efi\Gnd\Interface;

interface GndDatabaseServiceInterface
{
    function getNeighborhoodData(string $accession, int $windowSize): ?array;
}
