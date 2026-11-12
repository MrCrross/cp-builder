<?php

namespace App\Modules\Imports\Domain;

/**
 * Профиль импорта (план §13.1 — фиксированный профиль под подтверждённый
 * образец, не «любой XML»): CommerceML 3.1 (обмен с сайтом 1С, urn:1C.ru:
 * commerceml_3) и YML Bitrix-генератора по официальной спецификации.
 */
enum ImportProfile: string
{
    case CommerceML = 'commerceml';
    case Yml = 'yml';

    public function label(): string
    {
        return match ($this) {
            self::CommerceML => 'CommerceML 3.1 (1С)',
            self::Yml => 'YML (Яндекс Маркет)',
        };
    }
}
