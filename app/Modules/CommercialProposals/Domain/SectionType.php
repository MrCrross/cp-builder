<?php

namespace App\Modules\CommercialProposals\Domain;

enum SectionType: string
{
    case Stages = 'stages';
    case Custom = 'custom';
    case Table = 'table';
    case License = 'license';
    case PriceBadge = 'price_badge';
}
