<?php

namespace App\Modules\Imports\Contracts;

/**
 * Порт наполнения каталога (реализуется модулем Catalog — cross-module
 * Eloquent запрещён, план §6.1): upsert по unique (source, external_id),
 * деактивация по soft-delete флагу источника. Идемпотентно.
 */
interface CatalogImportPort
{
    /**
     * Fingerprint текущего состояния позиций каталога для определения
     * action add/change/skip без N+1.
     *
     * @param  list<string>  $externalIds
     * @return array<string, string> external_id => fingerprint
     */
    public function fingerprints(string $source, array $externalIds): array;

    /**
     * Создать или обновить позицию. Повтор вызова с теми же данными
     * не меняет запись.
     *
     * @return bool true — создана новая позиция
     */
    public function upsert(string $source, string $externalId, ImportedCatalogItem $item): bool;

    /**
     * Вывести позицию из обращения (soft-delete из источника).
     *
     * @return bool true — позиция была найдена и деактивирована
     */
    public function deactivate(string $source, string $externalId): bool;
}
