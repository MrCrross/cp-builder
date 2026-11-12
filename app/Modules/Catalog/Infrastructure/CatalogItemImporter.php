<?php

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Infrastructure\Persistence\CatalogItem;
use App\Modules\CommercialProposals\Domain\Money;
use App\Modules\Imports\Application\Contracts\CatalogImportPort;
use App\Modules\Imports\Application\Contracts\ImportedCatalogItem;
use Illuminate\Support\Facades\Storage;

/**
 * Реализация порта импорта каталога (контракт живёт в Imports — cross-module
 * Eloquent запрещён, план §6.1): upsert по unique (source, external_id),
 * единицы/цены нормализуются через Money VO. Категории источника не
 * импортируем (решение владельца этапа 5): у импортированных позиций
 * category_id = NULL, дерево каталога ведётся вручную. Идемпотентно:
 * повтор того же payload не меняет запись и не плодит дубли.
 *
 * Картинки: итоговое значение поля image присылает apply уже нормализованным
 * (ImportedCatalogItem::normalizeImage) — для CommerceML http(s) URL это
 * детерминированный путь в storage, файл докачивается здесь; сбой скачивания
 * не роняет позицию (у новой — image NULL, у существующей — прежнее значение).
 */
final class CatalogItemImporter implements CatalogImportPort
{
    public function __construct(
        private readonly ImportedImageFetcher $imageFetcher = new ImportedImageFetcher,
    ) {}

    /**
     * @param  list<string>  $externalIds
     * @return array<string, string>
     */
    public function fingerprints(string $source, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        $fingerprints = [];

        foreach (array_chunk($externalIds, 5000) as $chunk) {
            CatalogItem::query()
                ->where('source', $source)
                ->whereIn('external_id', $chunk)
                ->get(['external_id', 'name', 'description', 'unit', 'price', 'currency', 'properties', 'image', 'source_url'])
                ->each(function (CatalogItem $item) use (&$fingerprints): void {
                    $fingerprints[(string) $item->external_id] = ImportedCatalogItem::fingerprint(
                        $item->name,
                        $item->description,
                        $item->unit,
                        $item->price,
                        $item->currency,
                        $item->properties ?? [],
                        $item->image,
                        $item->source_url,
                    );
                });
        }

        return $fingerprints;
    }

    public function upsert(string $source, string $externalId, ImportedCatalogItem $item): bool
    {
        $existing = CatalogItem::query()
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->first();

        $attributes = [
            'name' => $item->name,
            'description' => $item->description,
            'unit' => $item->unit,
            'price' => Money::fromDecimalString($item->price)->toDecimalString(),
            'currency' => $item->currency === '' ? 'RUB' : $item->currency,
            'properties' => $item->properties === [] ? null : $item->properties,
            'source_url' => $item->sourceUrl,
        ];

        $image = $this->resolveImage($source, $item->image, $existing);

        if ($image !== null) {
            $attributes['image'] = $image;
        }

        if ($existing === null) {
            CatalogItem::query()->create($attributes + [
                'source' => $source,
                'external_id' => $externalId,
                'active' => true,
            ]);

            return true;
        }

        $existing->fill($attributes);
        if ($existing->isDirty()) {
            $existing->save();
        }

        return false;
    }

    public function deactivate(string $source, string $externalId): bool
    {
        $existing = CatalogItem::query()
            ->where('source', $source)
            ->where('external_id', $externalId)
            ->where('active', true)
            ->first();

        if ($existing === null) {
            return false;
        }

        $existing->update(['active' => false]);

        return true;
    }

    /**
     * Итоговое значение image: YML и относительные пути 1С — как есть;
     * http(s) URL 1С — детерминированный путь в storage (файл докачивается,
     * при сбое у существующей позиции остаётся прежнее значение, у новой —
     * NULL, чтобы не хранить мёртвую ссылку).
     */
    private function resolveImage(string $source, ?string $rawImage, ?CatalogItem $existing): ?string
    {
        if ($rawImage === null) {
            return $existing?->image;
        }

        $normalized = ImportedCatalogItem::normalizeImage($source, $rawImage);

        if ($source !== 'commerceml' || ! preg_match('#^https?://#i', $rawImage)) {
            return $normalized;
        }

        if (Storage::disk('local')->exists($normalized)) {
            return $normalized;
        }

        return $this->imageFetcher->fetchToStorage($rawImage, $normalized) ?? $existing?->image;
    }
}
