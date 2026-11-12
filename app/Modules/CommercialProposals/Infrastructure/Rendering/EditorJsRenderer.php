<?php

namespace App\Modules\CommercialProposals\Infrastructure\Rendering;

/**
 * Strict-рендерер Editor.js block JSON (план §9.3): allowlist типов блоков
 * и их полей, всё неизвестное отбрасывается, текст экранируется. Inline-HTML
 * в текстах допускает только b/strong/i/em/u/br/code и a[href] со схемами
 * https/mailto/tel (rel=noopener noreferrer). Ни script, ни style, ни unknown
 * blocks, ни embed'и — не выводятся.
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
     * Inline-HTML: allowlist тегов + безопасные ссылки. Неизвестные теги
     * удаляются вместе с атрибутами, их текст сохраняется.
     */
    public static function cleanInlineHtml(string $html): string
    {
        $allowed = strip_tags($html, '<a><b><strong><i><em><u><br><code>');

        // У не-<a> разрешённых тегов удаляем все атрибуты.
        $withoutAttrs = preg_replace('/<(b|strong|i|em|u|code)(\s[^>]*)?>/i', '<$1>', (string) $allowed) ?? '';

        // <a> пересобираем: только валидная схема, только href, rel добавляем.
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
        }, $withoutAttrs);
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
