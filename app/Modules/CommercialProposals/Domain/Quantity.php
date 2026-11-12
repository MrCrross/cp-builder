<?php

namespace App\Modules\CommercialProposals\Domain;

use InvalidArgumentException;

/**
 * Количество/часы — decimal string, точный расчёт через bcmath (§7.2).
 */
final readonly class Quantity implements \Stringable
{
    private const SCALE = 2;

    /** @var numeric-string */
    private string $value;

    private function __construct(string $value)
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Некорректное количество');
        }

        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        $normalized = str_replace(',', '.', trim($value));

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $normalized) || ! is_numeric($normalized)) {
            throw new InvalidArgumentException("Некорректное количество: {$value}");
        }

        return new self(bcadd($normalized, '0', self::SCALE));
    }

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    /** Transport-представление для numeric(14,2). */
    public function toDecimalString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
