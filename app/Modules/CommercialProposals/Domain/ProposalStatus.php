<?php

namespace App\Modules\CommercialProposals\Domain;

enum ProposalStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Archived = 'archived';
}
