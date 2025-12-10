<?php

namespace Efi\Gnd\Interface;

interface ExtentLookupInterface
{
    public function fetchExtent(ExtentParameterInterface $extentParameters): ExtentInterface;
}
