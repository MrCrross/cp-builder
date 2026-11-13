<?php

namespace App\Modules\CommercialProposals\Contracts;

use App\Modules\CommercialProposals\Data\CatalogItemSnapshot;

/**
 * Чтение актуальных значений номенклатуры для draft КП (план §6.1,
 * §7.3): реализация живёт в модуле Catalog, CommercialProposals не
 * импортирует его Eloquent-модели.
 */
interface CatalogItemLookupPort
{
    /**
     * @param  list<int>  $ids
     * @return array<int, CatalogItemSnapshot> ключ — id позиции
     */
    public function findByIds(array $ids): array;
}
