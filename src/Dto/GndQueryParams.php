<?php

namespace Efi\Gnd\Dto;

use Efi\Gnd\Enum\SequenceVersion;
use Efi\Gnd\Util\GndConstants;

readonly class GndQueryParams
{
    public function __construct(
        public int $window,
        public float $scaleFactor,
        public SequenceVersion $sequenceVersion,
        public ?string $query,
        public ?string $unirefId,
    )
    {
    }

    public static function getDefaultWindow(): int { return GndConstants::DEFAULT_WINDOW_SIZE; }
    public static function getDefaultScaleFactor(): float { return 0; }
    public static function getDefaultSequenceVersion(): SequenceVersion { return SequenceVersion::UniProt; }
}
