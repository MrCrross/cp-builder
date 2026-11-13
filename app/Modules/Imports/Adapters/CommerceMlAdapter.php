<?php

namespace App\Modules\Imports\Adapters;

use App\Modules\Imports\Contracts\ImportAdapter;
use App\Modules\Imports\Domain\Exceptions\ImportParseException;
use App\Modules\Imports\Domain\Services\PropertyDictionary;
use App\Modules\Imports\Domain\ValueObjects\ImportLimits;
use App\Modules\Imports\Domain\ValueObjects\ParsedItem;
use XMLReader;

/**
 * Профиль CommerceML 3.1 (обмен с сайтом 1С, urn:1C.ru:commerceml_3,
 * UTF-8 с BOM, русские теги). Подтверждён реальными выгрузками
 * (docs/imports-stage5-reference.md §1): инкрементальные чанки по 500
 * элементов; один цикл — классификатор → каталог → предложения/цены.
 * Файлы одного job'а мержатся staging'ом по <Ид> (GUID) товара.
 *
 * Категории (<Группы>) не сохраняем (решение владельца этапа 5). Свойства
 * товаров (<ЗначенияСвойств>) маппятся в характеристики: наименования
 * резолвятся по словарю классификатора (<Свойства> этого же job'а),
 * значения-справочники — по вариантам значений; неразрешимые ID пропускаются.
 * Остатки (<Остатки>) вне scope (план §18).
 */
final class CommerceMlAdapter implements ImportAdapter
{
    /** Источник цены для КП — тип «Розничная цена» (подтверждено владельцем). */
    private const string RETAIL_PRICE_TYPE = 'Розничная цена';

    /** ОКЕИ → обозначение единицы (в выгрузках только 796 = шт, план §1.5). */
    private const array OKEI_UNITS = [
        '796' => 'шт',
    ];

    private ?string $kind = null;

    private bool $sawClassifier = false;

    private bool $sawCatalog = false;

    private bool $sawOffers = false;

    private PropertyDictionary $dictionary;

    public function __construct(?PropertyDictionary $dictionary = null)
    {
        $this->dictionary = $dictionary ?? new PropertyDictionary;
    }

    public function detectedKind(): ?string
    {
        return $this->kind;
    }

    /**
     * @return iterable<ParsedItem>
     */
    public function parse(string $path, bool $definitionsOnly = false): iterable
    {
        // Вид файла — свойство одного файла; словарь свойств классификатора
        // накапливается между файлами цикла обмена.
        $this->kind = null;
        $this->sawClassifier = false;
        $this->sawCatalog = false;
        $this->sawOffers = false;

        $guard = GuardedXml::open($path);
        $reader = $guard->reader();

        try {
            if (! $guard->nextElement() || $reader->localName !== 'КоммерческаяИнформация') {
                throw ImportParseException::invalidFile(
                    'ожидался корень <КоммерческаяИнформация>, найден <'.$reader->localName.'>',
                );
            }

            $version = $reader->getAttribute('ВерсияСхемы');

            if ($version === null || ! str_starts_with($version, '3.')) {
                throw ImportParseException::invalidFile("ВерсияСхемы «{$version}» — профиль рассчитан на CommerceML 3.x");
            }

            $rootDepth = $reader->depth;

            while ($guard->read()) {
                if ($reader->depth <= $rootDepth) {
                    break; // </КоммерческаяИнформация>
                }

                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $rootDepth + 1) {
                    continue;
                }

                if ($reader->localName === 'Классификатор') {
                    $this->sawClassifier = true;
                    $this->parseClassifier($guard);
                } elseif ($reader->localName === 'Каталог') {
                    if ($definitionsOnly) {
                        $reader->next();
                    } else {
                        $this->sawCatalog = true;
                        yield from $this->parseCatalog($guard);
                    }
                } elseif ($reader->localName === 'ПакетПредложений') {
                    if ($definitionsOnly) {
                        $reader->next();
                    } else {
                        $this->sawOffers = true;
                        yield from $this->parseOffers($guard);
                    }
                } else {
                    $reader->next();
                }
            }

            $this->kind = match (true) {
                $this->sawCatalog => 'catalog',
                $this->sawOffers => 'offers',
                $this->sawClassifier => 'classifier',
                default => 'unknown',
            };
        } finally {
            $guard->close();
        }
    }

    /**
     * <Классификатор>: <Свойства> — в словарь адаптера (имена резолвятся
     * при разборе <ЗначенияСвойств> товаров). <Группы> категорий не
     * сохраняем (решение владельца этапа 5).
     */
    private function parseClassifier(GuardedXml $guard): void
    {
        $reader = $guard->reader();
        $depth = $reader->depth;

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === $depth + 1) {
                if ($reader->localName === 'Свойства') {
                    $this->parsePropertyDefs($guard);
                } else {
                    $reader->next();
                }
            }
        }
    }

    private function parsePropertyDefs(GuardedXml $guard): void
    {
        $reader = $guard->reader();
        $depth = $reader->depth; // <Свойства>

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $depth + 1 || $reader->localName !== 'Свойство') {
                continue;
            }

            $this->parsePropertyDef($guard);
        }
    }

    /**
     * Ридер стоит на <Свойство>; после возврата — на </Свойство>.
     */
    private function parsePropertyDef(GuardedXml $guard): void
    {
        $reader = $guard->reader();
        $depth = $reader->depth;
        $id = null;
        $name = null;

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $depth + 1) {
                continue;
            }

            if ($reader->localName === 'Ид') {
                $id = $guard->elementValue();
            } elseif ($reader->localName === 'Наименование') {
                $name = $guard->elementValue();
            } elseif ($reader->localName === 'ВариантыЗначений') {
                $this->parseVariants($guard);
            } else {
                $reader->next();
            }
        }

        if ($id !== null && $id !== '' && $name !== null && $name !== '') {
            $this->dictionary->addProperty($id, $name);
        }
    }

    /**
     * Варианты значений свойства (элементы-обёртки называются <Справочник>
     * или <ВариантЗначения> в зависимости от версии схемы).
     */
    private function parseVariants(GuardedXml $guard): void
    {
        $reader = $guard->reader();
        $depth = $reader->depth; // <ВариантыЗначений>

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $depth + 1) {
                continue;
            }

            $variantDepth = $reader->depth;
            $variantId = null;
            $variantValue = null;

            while ($guard->read()) {
                if ($reader->depth <= $variantDepth) {
                    break;
                }

                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $variantDepth + 1) {
                    continue;
                }

                if ($reader->localName === 'ИдЗначения' || $reader->localName === 'Ид') {
                    $variantId = $guard->elementValue();
                } elseif ($reader->localName === 'Значение') {
                    $variantValue = $guard->elementValue();
                } else {
                    $reader->next();
                }
            }

            if ($variantId !== null && $variantId !== '' && $variantValue !== null && $variantValue !== '') {
                $this->dictionary->addVariant($variantId, $variantValue);
            }
        }
    }

    /**
     * <Каталог> → <Товары><Товар>: внешний ID = <Ид> (GUID), единица из
     * <БазоваяЕдиница>@Код (ОКЕИ), характеристики — <ЗначенияСвойств>
     * через словарь классификатора, картинка — первый <Картинка>,
     * soft-delete — <ПометкаУдаления>true.
     *
     * @return iterable<ParsedItem>
     */
    private function parseCatalog(GuardedXml $guard): iterable
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

            if ($reader->localName === 'Товары') {
                yield from $this->parseProducts($guard);
            } else {
                $reader->next();
            }
        }
    }

    /**
     * @return iterable<ParsedItem>
     */
    private function parseProducts(GuardedXml $guard): iterable
    {
        $reader = $guard->reader();
        $depth = $reader->depth; // <Товары>

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === $depth + 1 && $reader->localName === 'Товар') {
                $item = $this->parseProduct($guard);

                if ($item !== null) {
                    yield $item;
                }
            }
        }
    }

    private function parseProduct(GuardedXml $guard): ?ParsedItem
    {
        $reader = $guard->reader();
        $depth = $reader->depth; // <Товар>
        $externalId = null;
        $name = null;
        $article = null;
        $description = null;
        $unit = null;
        $image = null;
        $properties = [];
        $delete = false;

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $depth + 1) {
                continue;
            }

            switch ($reader->localName) {
                case 'Ид':
                    $externalId = $guard->elementValue();
                    break;
                case 'Наименование':
                    $name = $guard->elementValue();
                    break;
                case 'Артикул':
                    $article = $guard->elementValue();
                    break;
                case 'Описание':
                    $description = $guard->elementValue();
                    break;
                case 'БазоваяЕдиница':
                    $unit = self::OKEI_UNITS[$reader->getAttribute('Код') ?? ''] ?? $reader->getAttribute('Код');
                    $reader->next();
                    break;
                case 'ПометкаУдаления':
                    $delete = $guard->elementValue() === 'true';
                    break;
                case 'Картинка':
                    if ($image === null) {
                        $value = trim($guard->elementValue());
                        $image = $value !== '' ? $value : null;
                    } else {
                        $reader->next();
                    }
                    break;
                case 'ЗначенияСвойств':
                    $properties = $this->parseProductProperties($guard);
                    break;
                default:
                    $reader->next();
            }
        }

        if ($externalId === null || $externalId === '') {
            return null; // элемент без ключа — пропуск без ошибки файла
        }

        return new ParsedItem(
            externalId: $externalId,
            name: $name !== '' ? $name : null,
            description: $description !== '' ? $description : null,
            unit: $unit,
            currency: 'RUB',
            article: $article !== '' ? $article : null,
            properties: $properties,
            image: $image,
            delete: $delete,
        );
    }

    /**
     * <ЗначенияСвойств> товара: <Ид> свойства резолвится в наименование
     * по словарю классификатора, значение-справочник — в текст варианта.
     * Неразрешимые ID и пустые значения пропускаются.
     *
     * @return list<array{name: string, value: string}>
     */
    private function parseProductProperties(GuardedXml $guard): array
    {
        $reader = $guard->reader();
        $depth = $reader->depth; // <ЗначенияСвойств>
        $properties = [];

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $depth + 1 || $reader->localName !== 'ЗначенияСвойства') {
                continue;
            }

            $pairDepth = $reader->depth;
            $propertyId = null;
            $value = null;

            while ($guard->read()) {
                if ($reader->depth <= $pairDepth) {
                    break;
                }

                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $pairDepth + 1) {
                    continue;
                }

                if ($reader->localName === 'Ид') {
                    $propertyId = $guard->elementValue();
                } elseif ($reader->localName === 'Значение') {
                    $value = $guard->elementValue();
                } else {
                    $reader->next();
                }
            }

            if ($propertyId === null || $propertyId === '') {
                continue;
            }

            $propertyName = $this->dictionary->propertyName($propertyId);

            if ($propertyName === null) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $properties = $this->appendProperty($properties, $propertyName, $this->dictionary->variantValue($value) ?? $value);
        }

        return $properties;
    }

    /**
     * @param  list<array{name: string, value: string}>  $properties
     * @return list<array{name: string, value: string}>
     *
     * @throws ImportParseException
     */
    private function appendProperty(array $properties, string $name, string $value): array
    {
        if (count($properties) >= ImportLimits::MAX_PROPERTIES_PER_ITEM) {
            throw ImportParseException::limitExceeded(ImportLimits::describe('MAX_PROPERTIES_PER_ITEM'));
        }

        $properties[] = ['name' => $name, 'value' => $value];

        return $properties;
    }

    /**
     * <ПакетПредложений> → <Предложения><Предложение>: <Ид> совпадает с
     * товаром (1:1), цена — <Цена> где <ИдТипаЦены> = «Розничная цена».
     *
     * @return iterable<ParsedItem>
     */
    private function parseOffers(GuardedXml $guard): iterable
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

            if ($reader->localName === 'Предложения') {
                yield from $this->parseOfferList($guard);
            } else {
                $reader->next();
            }
        }
    }

    /**
     * @return iterable<ParsedItem>
     */
    private function parseOfferList(GuardedXml $guard): iterable
    {
        $reader = $guard->reader();
        $depth = $reader->depth; // <Предложения>

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === $depth + 1 && $reader->localName === 'Предложение') {
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
        $depth = $reader->depth; // <Предложение>
        $externalId = null;
        $price = null;
        $currency = null;
        $delete = false;

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $depth + 1) {
                continue;
            }

            switch ($reader->localName) {
                case 'Ид':
                    $externalId = $guard->elementValue();
                    break;
                case 'ПометкаУдаления':
                    $delete = $guard->elementValue() === 'true';
                    break;
                case 'Цены':
                    [$price, $currency] = $this->parsePrices($guard);
                    break;
                default:
                    $reader->next();
            }
        }

        if ($externalId === null || $externalId === '') {
            return null;
        }

        return new ParsedItem(
            externalId: $externalId,
            price: $price,
            currency: $currency ?? 'RUB',
            delete: $delete,
        );
    }

    /**
     * @return array{0: ?string, 1: ?string} цена и валюта розничного типа
     */
    private function parsePrices(GuardedXml $guard): array
    {
        $reader = $guard->reader();
        $depth = $reader->depth; // <Цены>
        $candidates = [];

        while ($guard->read()) {
            if ($reader->depth <= $depth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $depth + 1 || $reader->localName !== 'Цена') {
                continue;
            }

            $priceDepth = $reader->depth;
            $type = null;
            $value = null;
            $valueCurrency = null;

            while ($guard->read()) {
                if ($reader->depth <= $priceDepth) {
                    break;
                }

                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $priceDepth + 1) {
                    continue;
                }

                if ($reader->localName === 'ИдТипаЦены') {
                    $type = $guard->elementValue();
                } elseif ($reader->localName === 'ЦенаЗаЕдиницу') {
                    $value = $guard->elementValue();
                } elseif ($reader->localName === 'Валюта') {
                    $valueCurrency = $guard->elementValue();
                } else {
                    $reader->next();
                }
            }

            $candidates[] = [$type, $value, $valueCurrency];
        }

        foreach ($candidates as [$type, $value, $valueCurrency]) {
            if ($type === self::RETAIL_PRICE_TYPE && $value !== null) {
                return [$value, $valueCurrency];
            }
        }

        return [null, null];
    }
}
