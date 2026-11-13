<?php

namespace App\Modules\CommercialProposals\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Деньги в целых копейках (план §7.2). Ни float, ни строки с плавающей точкой
 * не являются источником истины — только int копеек внутри и decimal string
 * снаружи (transport/БД numeric(20,2)).
 */
final readonly class Money implements \Stringable
{
    private const SCALE = 6;

    private function __construct(public int $kopecks) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromKopecks(int $kopecks): self
    {
        return new self($kopecks);
    }

    public function toKopecks(): int
    {
        return $this->kopecks;
    }

    /** Строка вида "1234.56" (рубли.копейки), неотрицательная. */
    public static function fromDecimalString(string $rubles): self
    {
        $normalized = str_replace(',', '.', trim($rubles));

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException("Некорректная сумма: {$rubles}");
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $kopecks = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return new self($kopecks);
    }

    /**
     * Стоимость строки: quantity × unitPrice с округлением half-up до копейки
     * на строку (утверждённое правило §7.2/§20.1).
     */
    public static function lineAmount(string $quantity, self $unitPrice): self
    {
        $price = $unitPrice->toDecimalString();

        if (! is_numeric($quantity) || ! is_numeric($price)) {
            throw new InvalidArgumentException('Некорректные операнды суммы строки');
        }

        $product = bcmul($quantity, $price, self::SCALE);

        return new self(self::roundHalfUpKopecks($product));
    }

    public function add(self $other): self
    {
        return new self($this->kopecks + $other->kopecks);
    }

    public function isZero(): bool
    {
        return $this->kopecks === 0;
    }

    public function compare(self $other): int
    {
        return $this->kopecks <=> $other->kopecks;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    /** Transport-представление: decimal string "1234.56" для numeric(20,2). */
    public function toDecimalString(): string
    {
        $sign = $this->kopecks < 0 ? '-' : '';
        $abs = abs($this->kopecks);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Отображение в документе: неразрывные пробелы-разделители тысяч + запятая. */
    public function toDisplayString(): string
    {
        $abs = abs($this->kopecks);
        $rubles = (string) intdiv($abs, 100);
        $grouped = '';

        while (strlen($rubles) > 3) {
            $grouped = "\u{00A0}".substr($rubles, -3).$grouped;
            $rubles = substr($rubles, 0, -3);
        }

        $grouped = $rubles.$grouped;
        $sign = $this->kopecks < 0 ? '−' : '';

        return $sign.$grouped.','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT)."\u{00A0}₽";
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    /**
     * Округление положительного decimal с SCALE знаками до копеек half-up.
     * bcround умеет только в PHP 8.4+ ext-bcmath; контракт фиксируем явно.
     */
    private static function roundHalfUpKopecks(string $decimal): int
    {
        if (! is_numeric($decimal) || bccomp($decimal, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException('Сумма строки не может быть отрицательной');
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, str_repeat('0', self::SCALE));
        $fraction = str_pad($fraction, self::SCALE, '0');

        $kopecks = (int) $whole * 100 + (int) substr($fraction, 0, 2);
        $remainder = substr($fraction, 2);

        $firstDigit = (int) $remainder[0];

        if ($firstDigit >= 5) {
            $kopecks++;
        }

        return $kopecks;
    }
}
