<?php

namespace App\Modules\Imports\Infrastructure\Xml;

use App\Modules\Imports\Domain\ImportLimits;
use App\Modules\Imports\Domain\ImportParseException;
use XMLReader;

/**
 * Обёртка XMLReader с guard-лимитами (план §9.5): LIBXML_NONET (без сети),
 * без подстановки entities (LIBXML_NOENT не включается), явный запрет
 * <!ENTITY в прологе (XXE fail-fast), лимиты глубины/узлов/длины текста.
 * DOM/SimpleXML целиком не используются — 197M-эталон YML разбирается
 * потоково в согласованном memory budget.
 */
final class GuardedXml
{
    private XMLReader $reader;

    private int $nodeCount = 0;

    private function __construct() {}

    /**
     * @throws ImportParseException
     */
    public static function open(string $path): self
    {
        self::assertNoEntityDeclarations($path);

        $xml = new self;
        $xml->reader = new XMLReader;

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $ok = $xml->reader->open($path, null, LIBXML_NONET);
            $error = libxml_get_last_error();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (! $ok || ($error !== false && $error->level >= LIBXML_ERR_ERROR)) {
            $xml->reader->close();

            throw ImportParseException::invalidFile(
                $error !== false ? trim($error->message) : 'не удалось открыть XML',
            );
        }

        return $xml;
    }

    /**
     * Внешний DOCTYPE (SYSTEM "shops.dtd") допустим — libxml без NOENT не
     * подставляет entities и с NONET не ходит в сеть; но любое объявление
     * <!ENTITY запрещаем явно (внутренний поднабор пролога сканируем сырьём).
     *
     * @throws ImportParseException
     */
    public static function assertNoEntityDeclarations(string $path): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ImportParseException::invalidFile('файл недоступен');
        }

        $prolog = (string) fread($handle, ImportLimits::PROLOG_SCAN_BYTES);
        fclose($handle);

        if (stripos($prolog, '<!ENTITY') !== false) {
            throw ImportParseException::invalidFile('объявления <!ENTITY запрещены (защита от XXE)');
        }
    }

    public function reader(): XMLReader
    {
        return $this->reader;
    }

    /**
     * Чтение с учётом лимитов; malformed-XML превращается в исключение.
     *
     * @throws ImportParseException
     */
    public function read(): bool
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $ok = $this->reader->read();
            $error = libxml_get_last_error();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (! $ok) {
            // Предупреждения (например, недоступный внешний DTD при NONET)
            // не считаются ошибкой разбора; останавливают ридер только
            // ERROR/FATAL (malformed XML, обрыв структуры).
            if ($error !== false && $error->level >= LIBXML_ERR_ERROR) {
                throw ImportParseException::invalidFile(trim($error->message));
            }

            return false;
        }

        $this->nodeCount++;
        if ($this->nodeCount > ImportLimits::MAX_NODE_COUNT) {
            throw ImportParseException::limitExceeded(ImportLimits::describe('MAX_NODE_COUNT'));
        }

        if ($this->reader->depth > ImportLimits::MAX_DEPTH) {
            throw ImportParseException::limitExceeded(ImportLimits::describe('MAX_DEPTH'));
        }

        if ($this->reader->nodeType === XMLReader::TEXT || $this->reader->nodeType === XMLReader::CDATA) {
            if (mb_strlen($this->reader->value) > ImportLimits::MAX_TEXT_LENGTH) {
                throw ImportParseException::limitExceeded(ImportLimits::describe('MAX_TEXT_LENGTH'));
            }
        }

        return true;
    }

    /**
     * Переместиться к следующему ELEMENT-узлу (для поиска корня).
     */
    public function nextElement(): bool
    {
        while ($this->read()) {
            if ($this->reader->nodeType === XMLReader::ELEMENT) {
                return true;
            }
        }

        return false;
    }

    /**
     * Простое текстовое содержимое элемента. Ридер стоит на START-элементе;
     * после вызова — на соответствующем END-элементе (или после него).
     * Пустые элементы (<Значение/>) не имеют отдельного END-события в
     * XMLReader — возвращаем '' сразу, не сдвигая ридер.
     */
    public function elementValue(): string
    {
        if ($this->reader->isEmptyElement) {
            return '';
        }

        $depth = $this->reader->depth;
        $value = '';

        while ($this->read()) {
            if ($this->reader->nodeType === XMLReader::TEXT || $this->reader->nodeType === XMLReader::CDATA) {
                $value .= $this->reader->value;
            }

            if ($this->reader->nodeType === XMLReader::END_ELEMENT && $this->reader->depth <= $depth) {
                break;
            }
        }

        return trim($value);
    }

    public function close(): void
    {
        $this->reader->close();
    }
}
