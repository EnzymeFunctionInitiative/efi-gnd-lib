<?php

namespace Efi\Gnd\Interface;

use Efi\Gnd\Enum\SequenceVersion;
use Efi\Gnd\Dto\GndMetadata;

interface GndReaderInterface
{
    function getSequenceVersion(): SequenceVersion;
    function getMetadata(): GndMetadata;
    function getSearchExtent(array $searchItems, GndQueryParams $params): array;
    function retrieveRanges(string $range, GndQueryParams $params): array;
}
