<?php

namespace Efi\Gnd\Enum;

enum SequenceVersion: string {
    case UniProt = 'uniprot';
    case UniRef90 = 'uniref90';
    case UniRef50 = 'uniref50';
}
