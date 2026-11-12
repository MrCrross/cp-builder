<?php

namespace App\Modules\CommercialProposals\Application\Data;

/**
 * Snapshot позиции каталога на момент обращения — transport-DTO,
 * не Eloquent-модуль Catalog (план §6.1: связь модулей через ports).
 */
final readonly class CatalogItemSnapshot
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $unit,
        public string $price,
    ) {}
}
