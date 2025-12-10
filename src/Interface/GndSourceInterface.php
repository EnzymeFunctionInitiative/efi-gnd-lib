<?php

namespace Efi\Gnd\Interface;

interface GndSourceInterface
{
    public function fetchData(ExtentLookupInterface $extentLookup): GndDataInterface;
}
