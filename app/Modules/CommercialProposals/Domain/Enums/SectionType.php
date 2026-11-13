<?php

namespace App\Modules\CommercialProposals\Domain\Enums;

enum SectionType: string
{
    case Items = 'items';
    case Stages = 'stages';
    case Custom = 'custom';
    case Table = 'table';
    case License = 'license';
    case PriceBadge = 'price_badge';
}
