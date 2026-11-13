<?php

use App\Modules\CommercialProposals\Domain\Enums\SectionType;
use App\Modules\CommercialProposals\Domain\Services\SectionPayloadValidator;
use App\Modules\CommercialProposals\Support\EditorJsRenderer;
use Illuminate\Validation\ValidationException;

it('renders wysiwyg html keeping allowed tags and alignment only', function () {
    $html = EditorJsRenderer::renderHtml(
        '<h2 style="text-align: center; color: red;" onclick="evil()">Заголовок</h2>'
        .'<p class="MsoNormal" style="margin:0">Текст <b style="font-size:40px">жирный</b></p>'
        .'<table class="x"><thead><tr><th>Колонка</th></tr></thead><tbody><tr><td>Ячейка</td></tr></tbody></table>'
        .'<ul><li>Пункт</li></ul>',
    );

    expect($html)
        ->toContain('<h2 style="text-align: center;">Заголовок</h2>')
        ->toContain('<p>Текст <b>жирный</b></p>')
        ->toContain('<table><thead><tr><th>Колонка</th></tr></thead><tbody><tr><td>Ячейка</td></tr></tbody></table>')
        ->toContain('<ul><li>Пункт</li></ul>')
        ->not->toContain('onclick')
        ->not->toContain('color')
        ->not->toContain('MsoNormal')
        ->not->toContain('font-size');
});

it('normalizes legacy align attribute to text-align style', function () {
    $html = EditorJsRenderer::renderHtml('<p align="center">Центр</p><div align="justify">По ширине</div>');

    expect($html)
        ->toContain('<p style="text-align: center;">Центр</p>')
        ->toContain('<div style="text-align: justify;">По ширине</div>');
});

it('strips dangerous tags and links from wysiwyg html', function () {
    $html = EditorJsRenderer::renderHtml(
        '<p>До<script>alert(1)</script>после</p>'
        .'<a href="javascript:alert(1)">плохая</a>'
        .'<a href="https://example.com" target="_blank">хорошая</a>'
        .'<iframe src="https://evil.example"></iframe>',
    );

    expect($html)
        // strip_tags удаляет теги, но сохраняет их текст (как cleanInlineHtml)
        ->toContain('Доalert(1)после')
        ->toContain('<a href="https://example.com" rel="noopener noreferrer">хорошая</a>')
        ->not->toContain('<script')
        ->not->toContain('javascript:')
        ->not->toContain('<iframe')
        ->not->toContain('target=');
});

it('renderSection prefers html over legacy blocks and falls back to blocks', function () {
    $blocks = [['type' => 'paragraph', 'data' => ['text' => 'Старый блок']]];

    expect(EditorJsRenderer::renderSection(['html' => '<p>Новый текст</p>', 'blocks' => $blocks]))
        ->toBe('<p>Новый текст</p>')
        ->and(EditorJsRenderer::renderSection(['blocks' => $blocks]))
        ->toBe('<p>Старый блок</p>')
        ->and(EditorJsRenderer::renderSection(['html' => '<p>  </p>', 'blocks' => $blocks]))
        ->toBe('<p>Старый блок</p>');
});

it('validates custom section with html payload', function () {
    $validated = SectionPayloadValidator::validate(SectionType::Custom, [
        'title' => null,
        'html' => '<p>Текст</p>',
    ]);

    expect($validated['html'])->toBe('<p>Текст</p>');
});

it('validates table columns with optional type', function () {
    $validated = SectionPayloadValidator::validate(SectionType::Table, [
        'columns' => [
            ['key' => 'col_1', 'label' => 'Наименование', 'type' => 'text'],
            ['key' => 'col_2', 'label' => 'Цена', 'type' => 'money'],
        ],
        'rows' => [['Работа', '100.00']],
        'price_column' => 'col_2',
        'include_in_total' => true,
    ]);

    expect($validated['columns'][1]['type'])->toBe('money');

    // Старые документы без type валидны.
    $legacy = SectionPayloadValidator::validate(SectionType::Table, [
        'columns' => [['key' => 'name', 'label' => 'Наименование']],
        'rows' => [],
    ]);

    expect($legacy['columns'][0])->toBe(['key' => 'name', 'label' => 'Наименование']);
});

it('shows human-readable messages for section payload errors', function () {
    try {
        SectionPayloadValidator::validate(SectionType::Table, [
            'columns' => [['key' => 'col_1', 'label' => '']],
        ]);
        $this->fail('Ожидалось ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['columns.0.label'][0])->toBe('Укажите название колонки.');
    }

    try {
        SectionPayloadValidator::validate(SectionType::Stages, [
            'stages' => [['title' => '', 'duration' => '']],
        ]);
        $this->fail('Ожидалось ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors()['stages.0.title'][0])->toBe('Укажите название этапа.')
            ->and($e->errors()['stages.0.duration'][0])->toBe('Укажите срок этапа.');
    }
});
