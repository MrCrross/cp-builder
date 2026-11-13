<?php

use App\Modules\Imports\Adapters\CommerceMlAdapter;
use App\Modules\Imports\Adapters\YmlAdapter;
use App\Modules\Imports\Domain\Exceptions\ImportParseException;
use App\Modules\Imports\Domain\ValueObjects\ParsedItem;

/**
 * Synthetic fixtures по образцам реальных выгрузок (план §14.4):
 * CommerceML 3.1 (русские теги, чанки, классификатор) и YML Bitrix
 * (DOCTYPE shops.dtd, квирки генератора). Реальные данные не используются.
 */
function importFixture(string $name): string
{
    return __DIR__.'/../../../Fixtures/imports/'.$name;
}

it('parses CommerceML classifier property dictionary without categories', function () {
    $adapter = new CommerceMlAdapter;

    $items = iterator_to_array($adapter->parse(importFixture('commerceml_classifier.xml')));

    expect($items)->toBeEmpty()
        ->and($adapter->detectedKind())->toBe('classifier');
});

it('parses CommerceML catalog products with properties resolved from classifier dictionary', function () {
    $adapter = new CommerceMlAdapter;

    // Один экземпляр адаптера на job: словарь свойств накапливается между
    // файлами цикла обмена (классификатор → каталог).
    iterator_to_array($adapter->parse(importFixture('commerceml_classifier.xml')));
    $items = iterator_to_array($adapter->parse(importFixture('commerceml_catalog.xml')));

    expect($adapter->detectedKind())->toBe('catalog')
        ->and($items)->toHaveCount(3);

    /** @var ParsedItem $first */
    $first = $items[0];
    expect($first->externalId)->toBe('11111111-2222-3333-4444-555555555501')
        ->and($first->name)->toBe('Амортизатор передний пример')
        ->and($first->article)->toBe('ART-001')
        ->and($first->unit)->toBe('шт')
        ->and($first->currency)->toBe('RUB')
        ->and($first->image)->toBe('https://files.example.test/import/amort-001.jpg')
        ->and($first->properties)->toBe([
            ['name' => 'Бренд пример', 'value' => 'ПримерБренд'],
        ])
        ->and($first->delete)->toBeFalse();

    /** @var ParsedItem $deleted */
    $deleted = $items[1];
    expect($deleted->delete)->toBeTrue()
        ->and($deleted->properties)->toBe([]);

    /** @var ParsedItem $relativeImage */
    $relativeImage = $items[2];
    expect($relativeImage->image)->toBe('import_files/pic-550503.jpg')
        ->and($relativeImage->properties)->toBe([]);
});

it('parses CommerceML offers keeping only retail price', function () {
    $adapter = new CommerceMlAdapter;
    $items = iterator_to_array($adapter->parse(importFixture('commerceml_prices.xml')));

    expect($adapter->detectedKind())->toBe('offers')
        ->and($items)->toHaveCount(3);

    /** @var ParsedItem $priced */
    $priced = $items[0];
    expect($priced->externalId)->toBe('11111111-2222-3333-4444-555555555501')
        ->and($priced->price)->toBe('1250.50')
        ->and($priced->currency)->toBe('RUB')
        ->and($priced->name)->toBeNull();

    /** @var ParsedItem $deleteOnly */
    $deleteOnly = $items[2];
    expect($deleteOnly->externalId)->toBe('11111111-2222-3333-4444-555555555504')
        ->and($deleteOnly->delete)->toBeTrue()
        ->and($deleteOnly->price)->toBeNull();
});

it('merges chunk items the way staging does (prices fill products)', function () {
    $product = new ParsedItem(
        externalId: 'id-1',
        name: 'Товар',
        unit: 'шт',
        currency: 'RUB',
        properties: [['name' => 'Цвет', 'value' => 'синий']],
        image: 'https://files.example.test/a.jpg',
    );
    $price = new ParsedItem(externalId: 'id-1', price: '100.00', currency: 'RUB');

    $merged = $product->merge($price);

    expect($merged->name)->toBe('Товар')
        ->and($merged->price)->toBe('100.00')
        ->and($merged->unit)->toBe('шт')
        ->and($merged->currency)->toBe('RUB')
        ->and($merged->properties)->toBe([['name' => 'Цвет', 'value' => 'синий']])
        ->and($merged->image)->toBe('https://files.example.test/a.jpg');
});

it('drops unknown property ids and keeps payload round-trip stable', function () {
    $item = new ParsedItem(
        externalId: 'id-1',
        name: 'Товар',
        properties: [['name' => 'Цвет', 'value' => 'синий'], ['name' => '', 'value' => 'x'], ['name' => 'Пусто', 'value' => '']],
    );

    $restored = ParsedItem::fromPayload($item->toPayload());

    expect($restored->properties)->toBe([['name' => 'Цвет', 'value' => 'синий']]);
});

it('accepts UTF-8 BOM like real 1C exports', function () {
    $bomPath = tempnam(sys_get_temp_dir(), 'import-bom-');
    file_put_contents($bomPath, "\xEF\xBB\xBF".file_get_contents(importFixture('commerceml_catalog.xml')));

    try {
        $adapter = new CommerceMlAdapter;
        $items = iterator_to_array($adapter->parse($bomPath));

        expect($items)->toHaveCount(3);
    } finally {
        unlink($bomPath);
    }
});

it('rejects CommerceML 2.x and wrong roots', function (string $contents, string $message) {
    $path = tempnam(sys_get_temp_dir(), 'import-bad-');
    file_put_contents($path, $contents);

    try {
        iterator_to_array((new CommerceMlAdapter)->parse($path));
    } catch (ImportParseException $exception) {
        expect($exception->getMessage())->toContain($message);

        return;
    } finally {
        unlink($path);
    }

    $this->fail('Ожидался ImportParseException');
})->with([
    '2.05 версия' => [
        '<?xml version="1.0"?><КоммерческаяИнформация xmlns="urn:1C.ru:commerceml_2" ВерсияСхемы="2.05"/>',
        'CommerceML 3.x',
    ],
    'чужой корень' => ['<?xml version="1.0"?><something/>', 'КоммерческаяИнформация'],
]);

it('parses YML offers with properties, picture and source url', function () {
    $adapter = new YmlAdapter;

    $items = iterator_to_array($adapter->parse(importFixture('yml_catalog.xml')));

    expect($adapter->detectedKind())->toBe('yml')
        ->and($items)->toHaveCount(2);

    /** @var ParsedItem $first */
    $first = $items[0];
    expect($first->externalId)->toBe('1001')
        ->and($first->name)->toBe('Часы пример кварцевые')
        ->and($first->price)->toBe('12500')
        ->and($first->currency)->toBe('RUB')
        ->and($first->article)->toBe('ART-1001')
        ->and($first->unit)->toBe('шт')
        ->and($first->description)->toBe('Тестовые часы с кварцевым механизмом')
        ->and($first->image)->toBe('https://example.test/img/1001.jpg')
        ->and($first->sourceUrl)->toBe('https://example.test/item/1001')
        ->and($first->properties)->toBe([
            ['name' => 'Водостойкость', 'value' => '0'],
            ['name' => 'Цвет', 'value' => 'серебристый'],
        ]);

    /** @var ParsedItem $composedName */
    $composedName = $items[1];
    expect($composedName->externalId)->toBe('1002')
        ->and($composedName->name)->toBe('ПримерБренд PB-200')
        ->and($composedName->price)->toBe('990.90')
        ->and($composedName->image)->toBe('https://example.test/img/1002.jpg')
        ->and($composedName->sourceUrl)->toBe('https://example.test/item/1002');
});

it('rejects XXE entity declarations', function () {
    try {
        iterator_to_array((new YmlAdapter)->parse(importFixture('xxe.xml')));
    } catch (ImportParseException $exception) {
        expect($exception->getMessage())->toContain('<!ENTITY');

        return;
    }

    $this->fail('Ожидался ImportParseException');
});

it('enforces depth limit on deeply nested files', function () {
    try {
        iterator_to_array((new YmlAdapter)->parse(importFixture('deep.xml')));
    } catch (ImportParseException $exception) {
        expect($exception->getMessage())->toContain('глубина вложенности');

        return;
    }

    $this->fail('Ожидался ImportParseException');
});

it('rejects malformed XML', function () {
    try {
        iterator_to_array((new CommerceMlAdapter)->parse(importFixture('malformed.xml')));
    } catch (ImportParseException $exception) {
        expect($exception->getMessage())->not->toBeEmpty();

        return;
    }

    $this->fail('Ожидался ImportParseException');
});

it('enforces text length limit', function () {
    try {
        iterator_to_array((new YmlAdapter)->parse(importFixture('oversized_text.xml')));
    } catch (ImportParseException $exception) {
        expect($exception->getMessage())->toContain('длина текстового значения');

        return;
    }

    $this->fail('Ожидался ImportParseException');
});

it('parses a large generated YML within the memory budget', function () {
    $offers = 20000;
    $chunk = str_repeat('<offer id="X" type="vendor.model" available="true"><price>100</price><currencyId>RUR</currencyId><categoryId>10</categoryId><name>Синтетическая позиция каталога для нагрузочной проверки разборщика</name><param name="Артикул">SYN-0001</param><param name="Материал">Сталь сплавная</param></offer>', 1);

    $path = tempnam(sys_get_temp_dir(), 'import-large-');

    $handle = fopen($path, 'wb');
    fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE yml_catalog SYSTEM "shops.dtd"><yml_catalog date="2026-11-12T16:00:02+08:00"><shop><name>Нагрузочный пример</name><url>https://example.test</url><currencies><currency id="RUR" rate="1"/></currencies><categories><category id="10">Синтетика</category></categories><offers>');

    for ($i = 1; $i <= $offers; $i++) {
        fwrite($handle, str_replace('id="X"', 'id="'.(500000 + $i).'"', $chunk));
    }

    fwrite($handle, '</offers></shop></yml_catalog>');
    fclose($handle);

    $sizeBytes = filesize($path);
    $memoryBefore = memory_get_usage(true);

    try {
        $count = 0;
        foreach ((new YmlAdapter)->parse($path) as $item) {
            $count++;
        }

        $peakDelta = memory_get_peak_usage(true) - $memoryBefore;

        expect($count)->toBe($offers)
            ->and($sizeBytes)->toBeGreaterThan(5 * 1024 * 1024)
            ->and($peakDelta)->toBeLessThan(64 * 1024 * 1024);
    } finally {
        unlink($path);
    }
});
