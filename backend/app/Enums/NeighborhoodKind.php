<?php

namespace App\Enums;

/**
 * Kind of locality, only when a source states it (otherwise Unspecified). Values are the data-file vocabulary.
 */
enum NeighborhoodKind: string
{
    case Quarter = 'quartier';
    case Village = 'village';
    case Locality = 'localite';
    case Unspecified = 'non_precise';
}
