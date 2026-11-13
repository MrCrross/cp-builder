<?php

namespace App\Modules\Imports\Adapters;

use App\Modules\Imports\Contracts\ImportAdapter;
use App\Modules\Imports\Domain\Exceptions\ImportParseException;
use App\Modules\Imports\Domain\ValueObjects\ImportLimits;
use App\Modules\Imports\Domain\ValueObjects\ParsedItem;
use XMLReader;

/**
 * Профиль YML по официальной спецификации Яндекс Маркета, подтверждённый
 * реальной полной выгрузкой Bitrix (76k offers type="vendor.model",
 * docs/imports-stage5-reference.md §2). Квирки генератора: внешний
 * DOCTYPE shops.dtd (допустим — entities не подставляются), повторный
 * <name> (берём первый), нестандартный <count>, артикул в
 * <param name="Артикул">, пустые нулевые param (не маппятся).
 *
 * Внешний ID = offer/@id (строка). Цена — <price> (<oldprice> — только
 * скидочная метка, в КП не идёт). Категории <categories> не сохраняем
 * (решение владельца этапа 5). <picture> и <url> — ссылки как есть
 * (внешние URL, скачивание не требуется). Характеристики — все <param>
 * кроме «Артикул» (он идёт в отдельное поле).
 */
final class YmlAdapter implements ImportAdapter
{
    private ?string $kind = null;

    public function detectedKind(): ?string
    {
        return $this->kind;
    }

    /**
     * @return iterable<ParsedItem>
     */
    public function parse(string $path, bool $definitionsOnly = false): iterable
    {
        $guard = GuardedXml::open($path);
        $reader = $guard->reader();

        try {
            if (! $guard->nextElement() || $reader->localName !== 'yml_catalog') {
                throw ImportParseException::invalidFile(
                    'ожидался корень <yml_catalog>, найден <'.$reader->localName.'>',
                );
            }

            $rootDepth = $reader->depth;

            while ($guard->read()) {
                if ($reader->depth <= $rootDepth) {
                    break;
                }

                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $rootDepth + 1) {
                    continue;
                }

                if ($reader->localName === 'shop') {
                    yield from $this->parseShop($guard);
                } else {
                    $reader->next();
                }
            }

            $this->kind = 'yml';
        } finally {
            $guard->close();
        }
    }

    /**
     * @return iterable<ParsedItem>
     */
    private function parseShop(GuardedXml $guard): iterable
    {
        $reader = $guard->reader();
        $depth = $reader->depth;

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $depth + 1) {
                continue;
            }

            if ($reader->localName === 'offers') {
                yield from $this->parseOffers($guard);
            } else {
                // <categories> и прочее служебное не сохраняем.
                $reader->next();
            }
        }
    }

    /**
     * @return iterable<ParsedItem>
     */
    private function parseOffers(GuardedXml $guard): iterable
    {
        $reader = $guard->reader();
        $depth = $reader->depth; // <offers>

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === $depth + 1 && $reader->localName === 'offer') {
                $item = $this->parseOffer($guard);

                if ($item !== null) {
                    yield $item;
                }
            }
        }
    }

    private function parseOffer(GuardedXml $guard): ?ParsedItem
    {
        $reader = $guard->reader();
        $depth = $reader->depth; // <offer>
        $externalId = $reader->getAttribute('id');

        $price = null;
        $currency = null;
        $name = null;
        $vendor = null;
        $model = null;
        $typePrefix = null;
        $description = null;
        $article = null;
        $sourceUrl = null;
        $image = null;
        $properties = [];

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $depth + 1) {
                continue;
            }

            switch ($reader->localName) {
                case 'price':
                    $price = $guard->elementValue();
                    break;
                case 'currencyId':
                    $currency = $guard->elementValue();
                    break;
                case 'url':
                    $sourceUrl = $this->nonEmptyValue($guard);
                    break;
                case 'picture':
                    if ($image === null) {
                        $image = $this->nonEmptyValue($guard);
                    } else {
                        $reader->next();
                    }
                    break;
                case 'name':
                    // Квирк генератора: <name> повторяется в конце offer —
                    // берём первое вхождение.
                    if ($name === null) {
                        $name = $guard->elementValue();
                    } else {
                        $reader->next();
                    }
                    break;
                case 'vendor':
                    $vendor = $guard->elementValue();
                    break;
                case 'model':
                    $model = $guard->elementValue();
                    break;
                case 'typePrefix':
                    $typePrefix = $guard->elementValue();
                    break;
                case 'description':
                    $description = $guard->elementValue();
                    break;
                case 'param':
                    $paramName = trim((string) $reader->getAttribute('name'));
                    $value = trim($guard->elementValue());

                    // Квирк: артикул живёт в param, не в <vendorCode>.
                    if ($paramName === 'Артикул') {
                        $article = $value !== '' ? $value : $article;
                    } elseif ($paramName !== '' && $value !== '') {
                        if (count($properties) >= ImportLimits::MAX_PROPERTIES_PER_ITEM) {
                            throw ImportParseException::limitExceeded(ImportLimits::describe('MAX_PROPERTIES_PER_ITEM'));
                        }

                        $properties[] = ['name' => $paramName, 'value' => $value];
                    }
                    break;
                default:
                    // oldprice/sales_notes/barcode/count и прочее не маппятся.
                    $reader->next();
            }
        }

        if ($externalId === null || $externalId === '') {
            return null;
        }

        $name = $name !== null && $name !== ''
            ? $name
            : trim(implode(' ', array_filter([$typePrefix, $vendor, $model], fn (?string $part) => $part !== null && $part !== '')));

        return new ParsedItem(
            externalId: $externalId,
            name: $name !== '' ? $name : null,
            description: $description !== null && $description !== '' ? $description : null,
            unit: 'шт',
            price: $price,
            currency: self::normalizeCurrency($currency),
            article: $article,
            properties: $properties,
            image: $image,
            sourceUrl: $sourceUrl,
        );
    }

    /**
     * Текст элемента; пустое значение → null.
     */
    private function nonEmptyValue(GuardedXml $guard): ?string
    {
        $value = trim($guard->elementValue());

        return $value !== '' ? $value : null;
    }

    /**
     * В выгрузках Яндекс-стиля валюта «RUR» — это рубль (ISO RUB).
     */
    private static function normalizeCurrency(?string $currency): ?string
    {
        if ($currency === null || $currency === '') {
            return null;
        }

        return $currency === 'RUR' ? 'RUB' : $currency;
    }
}
