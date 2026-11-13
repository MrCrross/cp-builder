<?php

use App\Modules\CommercialProposals\Domain\ValueObjects\Money;
use App\Modules\CommercialProposals\Domain\ValueObjects\Quantity;

it('parses decimal strings with dot and comma', function () {
    expect(Money::fromDecimalString('1234.56')->toDecimalString())->toBe('1234.56')
        ->and(Money::fromDecimalString('0.5')->toDecimalString())->toBe('0.50')
        ->and(Money::fromDecimalString('42')->toDecimalString())->toBe('42.00')
        ->and(Money::fromDecimalString('1,25')->toDecimalString())->toBe('1.25');
});

it('rejects malformed money strings', function (string $input) {
    Money::fromDecimalString($input);
})->with(['', 'abc', '-5', '1.234', '1,2,3', '1.2.3', '  '])
    ->throws(InvalidArgumentException::class);

it('rounds line amounts half-up to kopecks', function (string $quantity, string $price, int $expectedKopecks) {
    $amount = Money::lineAmount($quantity, Money::fromDecimalString($price));

    expect($amount->toKopecks())->toBe($expectedKopecks);
})->with([
    ['10', '100.00', 100000],
    ['0.1', '0.29', 3],           // 0.029 ₽ = 2.9 коп → 3 коп
    ['0.1', '0.28', 3],           // 0.028 ₽ = 2.8 коп → 3 коп
    ['2.5', '0.01', 3],           // 0.025 ₽ — ровно половина копейки → вверх
    ['1.2', '0.02', 2],           // 0.024 ₽ — меньше половины → вниз
    ['3', '33.34', 10002],        // 100.02 ₽ → 10002 коп
    ['2.5', '0.01', 3],
    ['1', '0.02', 2],
    ['2.5', '100.00', 25000],
    ['0', '100.00', 0],
]);

it('rejects negative line amounts', function () {
    Money::lineAmount('-1', Money::fromDecimalString('100'));
})->throws(InvalidArgumentException::class);

it('adds amounts exactly', function () {
    $total = Money::fromDecimalString('0.29')->add(Money::lineAmount('0.1', Money::fromDecimalString('0.29')));

    expect($total->toDecimalString())->toBe('0.32');
});

it('formats display strings with non-breaking spaces', function () {
    expect(Money::fromDecimalString('1234567.89')->toDisplayString())->toBe("1\u{00A0}234\u{00A0}567,89\u{00A0}₽")
        ->and(Money::fromDecimalString('0.05')->toDisplayString())->toBe("0,05\u{00A0}₽");
});

it('compares money exactly', function () {
    $a = Money::fromDecimalString('10.00');
    $b = Money::fromDecimalString('9.99');

    expect($a->greaterThan($b))->toBeTrue()
        ->and($a->compare($b))->toBe(1)
        ->and($a->compare(Money::fromDecimalString('10')))->toBe(0);
});

it('parses and compares quantities', function () {
    $min = Quantity::fromString('4');
    $max = Quantity::fromString('8.5');

    expect($min->toDecimalString())->toBe('4.00')
        ->and($max->toDecimalString())->toBe('8.50')
        ->and($min->lessThan($max))->toBeTrue()
        ->and(Quantity::fromString('8,5')->toDecimalString())->toBe('8.50');
});

it('rejects malformed quantities', function (string $input) {
    Quantity::fromString($input);
})->with(['', '-1', '1.234', 'abc'])->throws(InvalidArgumentException::class);
