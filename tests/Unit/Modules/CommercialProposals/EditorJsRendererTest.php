<?php

use App\Modules\CommercialProposals\Support\EditorJsRenderer;

it('renders allowed blocks with escaped text', function () {
    $html = EditorJsRenderer::render([
        'blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Просто текст <b>жирный</b> и <script>alert(1)</script>']],
            ['type' => 'header', 'data' => ['text' => 'Заголовок <i>курсив</i>', 'level' => 2]],
            ['type' => 'list', 'data' => ['style' => 'ordered', 'items' => ['Первый', 'Второй']]],
            ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['Наименование', 'Цена'], ['Работа', '100']]]],
        ],
    ]);

    expect($html)->toContain('<p>Просто текст <b>жирный</b> и alert(1)</p>')
        ->toContain('<h2>Заголовок <i>курсив</i></h2>')
        ->toContain('<ol><li>Первый</li><li>Второй</li></ol>')
        ->toContain('<th>Наименование</th>')
        ->toContain('<td>Работа</td>');
});

it('drops unknown block types and malformed blocks', function () {
    $html = EditorJsRenderer::render([
        'blocks' => [
            ['type' => 'raw', 'data' => ['html' => '<script>alert(1)</script>']],
            ['type' => 'embed', 'data' => ['source' => 'https://evil.example']],
            ['type' => 'paragraph'],
            'not-a-block',
            ['type' => 'paragraph', 'data' => ['text' => 'Остался']],
        ],
    ]);

    expect($html)->toBe('<p>Остался</p>');
});

it('sanitizes inline links keeping only allowed schemes', function () {
    $html = EditorJsRenderer::render([
        'blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => '<a href="https://example.com/x" onclick="evil()">ok</a>'
                .'<a href="javascript:alert(1)">no</a>'
                .'<a href="mailto:test@example.com">mail</a>'
                .'<a href="tel:+79990000000">tel</a>'
                .'<a href="http://insecure.example">http</a>'
                .'<a href="data:text/html,x">data</a>',
            ]],
        ],
    ]);

    expect($html)
        ->toContain('<a href="https://example.com/x" rel="noopener noreferrer">ok</a>')
        ->toContain('>mail</a>')
        ->toContain('>tel</a>')
        ->not->toContain('javascript:')
        ->not->toContain('onclick')
        ->not->toContain('http://insecure')
        ->not->toContain('data:text/html')
        // закрывающие теги без открывающих не создают элементов
        ->toContain('no</a>');
});

it('strips attributes from inline formatting tags', function () {
    $html = EditorJsRenderer::render([
        'blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => '<b onmouseover="x()" style="position:fixed">жирный</b><u class="y">подч</u>']],
        ],
    ]);

    expect($html)->toBe('<p><b>жирный</b><u>подч</u></p>');
});

it('rejects non-array and oversized content', function () {
    expect(EditorJsRenderer::render('string'))->toBe('')
        ->and(EditorJsRenderer::render(null))->toBe('')
        ->and(EditorJsRenderer::render(['blocks' => 'nope']))->toBe('');
});

it('limits table and list sizes', function () {
    $blocks = [];
    for ($i = 0; $i < 500; $i++) {
        $blocks[] = ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => array_fill(0, 500, 'x')]];
    }

    $html = EditorJsRenderer::render(['blocks' => $blocks]);

    expect(substr_count($html, '<li>'))->toBe(100 * 200); // MAX_BLOCKS × лимит items
});
