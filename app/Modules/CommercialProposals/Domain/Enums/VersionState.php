<?php

namespace App\Modules\CommercialProposals\Domain\Enums;

enum VersionState: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Superseded = 'superseded';
}
