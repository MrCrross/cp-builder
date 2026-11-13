<?php

namespace App\Modules\Imports\Contracts;

use App\Modules\Imports\Domain\Exceptions\ImportParseException;
use App\Modules\Imports\Domain\ValueObjects\ParsedItem;

/**
 * Сменный адаптер профиля импорта (план §13.3: integrations core видит
 * только внутренние DTO, адаптер остаётся сменным). Потоковый: yield'ит
 * ParsedItem, ничего не буферизуя целиком.
 */
interface ImportAdapter
{
    /**
     * Потоковый разбор файла. Один экземпляр адаптера живёт весь job:
     * словари секций (например, наименования свойств классификатора 1С)
     * накапливаются между файлами цикла обмена.
     *
     * $definitionsOnly — первый проход разбора job'а: адаптер читает только
     * определения (словарь свойств CommerceML), позиции пропускает. Порядок
     * файлов цикла обмена не гарантирует классификатор раньше каталога.
     *
     * @return iterable<ParsedItem>
     *
     * @throws ImportParseException
     */
    public function parse(string $path, bool $definitionsOnly = false): iterable;

    /**
     * Вид разобранного файла (classifier/catalog/offers/yml) — заполняется
     * в ходе итерации результата parse().
     */
    public function detectedKind(): ?string;
}
