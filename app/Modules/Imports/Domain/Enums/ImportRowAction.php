<?php

namespace App\Modules\Imports\Domain\Enums;

/** Действие staging-строки относительно текущего состояния каталога. */
enum ImportRowAction: string
{
    case Add = 'add';
    case Change = 'change';
    case Skip = 'skip';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Add => 'Добавление',
            self::Change => 'Изменение',
            self::Skip => 'Без изменений',
            self::Delete => 'Отключение',
        };
    }
}
