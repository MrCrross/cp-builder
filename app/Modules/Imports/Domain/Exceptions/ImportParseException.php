<?php

namespace App\Modules\Imports\Domain\Exceptions;

use RuntimeException;

/**
 * Постоянная ошибка разбора/валидации файла (план §13.1: permanent
 * parse/validation errors без retry — job помечается failed сразу).
 */
final class ImportParseException extends RuntimeException
{
    public static function invalidFile(string $reason): self
    {
        return new self("Файл не соответствует профилю импорта: {$reason}");
    }

    public static function limitExceeded(string $limit): self
    {
        return new self("Превышен лимит безопасности разбора: {$limit}");
    }
}
