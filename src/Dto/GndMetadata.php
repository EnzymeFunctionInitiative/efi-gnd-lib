<?php

namespace Efi\Gnd\Dto;

use Efi\Gnd\Enum\SequenceVersion;

readonly class GndMetadata
{
    public function __construct(
        public ?string $jobName,
        public ?float $cooccurrence,
        public ?int $neighborhoodSize,
        public SequenceVersion $sequenceVersion,
        public int $numClusters,
        public ?int $firstClusterNum,
    )
    {
    }
}
