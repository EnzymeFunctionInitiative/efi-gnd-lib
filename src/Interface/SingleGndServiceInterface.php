<?php

namespace Efi\Gnd\Interface;

interface SingleGndServiceInterface
{
    function getNeighborhoodData(string $accession, int $windowSize): ?array;
}
