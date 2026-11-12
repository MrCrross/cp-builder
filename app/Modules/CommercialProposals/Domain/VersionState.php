<?php

namespace App\Modules\CommercialProposals\Domain;

enum VersionState: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Superseded = 'superseded';
}
