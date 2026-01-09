<?php

namespace Efi\Gnd;

use Efi\Gnd\Interface\GndReaderInterface;
use \PDO;

class GndReaderMySQL extends GndReader implements GndReaderInterface
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
    }
}
