<?php

namespace App\Modules\CommercialProposals\Support;

/**
 * Strict-рендерер текстового контента секций (план §9.3): два формата —
 * legacy Editor.js block JSON ({@see render}) и единый HTML-документ из
 * WYSIWYG-редактора ({@see renderHtml}); выбор — в {@see renderSection}.
 * Allowlist типов и тегов, всё неизвестное отбрасывается, ссылки — только
 * со схемами https/mailto/tel (rel=noopener noreferrer). Ни script, ни
 * style, ни unknown blocks, ни embed'и — не выводятся.
 */
final class EditorJsRenderer
{
    private const ALLOWED_SCHEMES = ['https', 'mailto', 'tel'];

    private const MAX_BLOCKS = 100;

    /**
     * @param  array<string, mixed>|mixed  $content
     */
    public static function render(mixed $content): string
    {
        if (! is_array($content)) {
            return '';
        }

        $blocks = $content['blocks'] ?? null;

        if (! is_array($blocks)) {
            return '';
        }

        $html = '';

        foreach (array_slice($blocks, 0, self::MAX_BLOCKS) as $block) {
            if (! is_array($block) || ! isset($block['type'], $block['data']) || ! is_array($block['data'])) {
                continue;
            }

            $html .= match ($block['type']) {
                'paragraph' => self::paragraph($block['data']),
                'header' => self::header($block['data']),
                'list' => self::list($block['data']),
                'table' => self::table($block['data']),
                default => '',
            };
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function paragraph(array $data): string
    {
        $text = self::stringField($data, 'text');

        return $text === null ? '' : '<p>'.self::cleanInlineHtml($text).'</p>';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function header(array $data): string
    {
        $text = self::stringField($data, 'text');
        $level = (int) ($data['level'] ?? 2);

        if ($text === null || $level < 1 || $level > 4) {
            return '';
        }

        return "<h{$level}>".self::cleanInlineHtml($text)."</h{$level}>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function list(array $data): string
    {
        $items = $data['items'] ?? null;

        if (! is_array($items)) {
            return '';
        }

        $tag = ($data['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';
        $html = "<{$tag}>";

        foreach (array_slice($items, 0, 200) as $item) {
            $text = is_string($item) ? $item : (is_array($item) && is_string($item['content'] ?? null) ? $item['content'] : null);

            if ($text === null) {
                continue;
            }

            $html .= '<li>'.self::cleanInlineHtml($text).'</li>';
        }

        return $html."</{$tag}>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function table(array $data): string
    {
        $rows = $data['content'] ?? null;

        if (! is_array($rows)) {
            return '';
        }

        $withHeadings = (bool) ($data['withHeadings'] ?? false);
        $html = '<table><tbody>';

        foreach (array_slice($rows, 0, 200) as $rowIndex => $row) {
            if (! is_array($row)) {
                continue;
            }

            $cellTag = $withHeadings && $rowIndex === 0 ? 'th' : 'td';
            $html .= '<tr>';

            foreach (array_slice($row, 0, 20) as $cell) {
                $html .= '<'.$cellTag.'>'.(is_string($cell) ? self::cleanInlineHtml($cell) : '').'</'.$cellTag.'>';
            }

            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    /**
     * Рендер секции custom: новый формат — единый HTML-документ (WYSIWYG),
     * старый — Editor.js block JSON. Оба пути идут через allowlist-санитайзеры.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function renderSection(array $payload): string
    {
        $html = $payload['html'] ?? null;

        if (is_string($html) && trim(strip_tags($html)) !== '') {
            return self::renderHtml($html);
        }

        return self::render(['blocks' => $payload['blocks'] ?? []]);
    }

    /**
     * Блочный HTML из WYSIWYG-редактора: allowlist тегов, у не-<a> тегов
     * атрибуты удаляются (кроме text-align на блочных), <a> — только
     * href с безопасной схемой и rel=noopener noreferrer.
     */
    public static function renderHtml(string $html): string
    {
        $stripped = strip_tags(
            $html,
            '<p><div><h1><h2><h3><ul><ol><li><table><thead><tbody><tr><th><td><b><strong><i><em><u><br><code><a>',
        );

        // Блочные теги: сохраняем только выравнивание (style text-align или
        // устаревший align), остальные атрибуты удаляем.
        $withoutAttrs = (string) preg_replace_callback(
            '/<(p|div|h1|h2|h3|li|td|th)(\s[^>]*)?>/i',
            function (array $m): string {
                $tag = strtolower($m[1]);
                $attrs = $m[2] ?? '';
                $align = null;

                if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $attrs, $mm) === 1) {
                    $align = strtolower($mm[1]);
                } elseif (preg_match('/align\s*=\s*(?:"([a-z]+)"|\'([a-z]+)\'|([a-z]+))/i', $attrs, $mm) === 1) {
                    foreach ([1, 2, 3] as $offset) {
                        if (isset($mm[$offset]) && $mm[$offset] !== '') {
                            $align = strtolower($mm[$offset]);

                            break;
                        }
                    }
                }

                if ($align === null || ! in_array($align, ['left', 'center', 'right', 'justify'], true)) {
                    return '<'.$tag.'>';
                }

                return '<'.$tag.' style="text-align: '.$align.';">';
            },
            $stripped,
        );

        // Структурные и инлайновые теги: атрибуты не сохраняем вовсе.
        $clean = (string) preg_replace(
            '/<(ul|ol|table|thead|tbody|tr|b|strong|i|em|u|code|br)(\s[^>]*)?>/i',
            '<$1>',
            $withoutAttrs,
        );

        return self::rebuildLinks($clean);
    }

    /**
     * Inline-HTML: allowlist тегов + безопасные ссылки. Неизвестные теги
     * удаляются вместе с атрибутами, их текст сохраняется.
     */
    public static function cleanInlineHtml(string $html): string
    {
        $allowed = strip_tags($html, '<a><b><strong><i><em><u><br><code>');

        // У не-<a> разрешённых тегов удаляем все атрибуты.
        $withoutAttrs = preg_replace('/<(b|strong|i|em|u|code)(\s[^>]*)?>/i', '<$1>', (string) $allowed) ?? '';

        return self::rebuildLinks($withoutAttrs);
    }

    /**
     * <a> пересобираем: только валидная схема, только href, rel добавляем.
     */
    private static function rebuildLinks(string $html): string
    {
        return (string) preg_replace_callback('/<a\s[^>]*>/i', function (array $m): string {
            if (preg_match('/href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $m[0], $mm) !== 1) {
                return '';
            }

            $raw = '';
            foreach ([2, 3, 4] as $offset) {
                if (isset($mm[$offset]) && $mm[$offset] !== '') {
                    $raw = $mm[$offset];

                    break;
                }
            }

            $href = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
            $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

            if ($href === '' || $href === '0' || ! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
                return '';
            }

            return '<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'" rel="noopener noreferrer">';
        }, $html);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function stringField(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
