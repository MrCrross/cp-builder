/**
 * Модель блоков конструктора КП: типизированные секции, миграция legacy
 * Editor.js-блоков в HTML для WYSIWYG-редактора, клиентская очистка
 * вставляемого HTML и помощники для визуального редактора таблиц.
 */

import type { ProposalItemInput } from '@/shared/api';

export interface Stage {
    title: string;
    duration: string;
}

export type TableColumnType = 'text' | 'number' | 'money';

export interface TableColumn {
    /** Технический ключ: генерируется автоматически, пользователю не показывается. */
    key: string;
    label: string;
    type: TableColumnType;
}

export interface TablePayload {
    title?: string | null;
    columns: TableColumn[];
    rows: string[][];
    price_column: string | null;
    include_in_total: boolean;
}

export interface LicenseItem {
    name: string;
    period: string;
    price: string;
}

export type SectionRow =
    | {
          type: 'items';
          enabled: boolean;
          payload: { title?: string | null; items: ProposalItemInput[] };
      }
    | {
          type: 'stages';
          enabled: boolean;
          payload: { title?: string | null; stages: Stage[] };
      }
    | {
          type: 'custom';
          enabled: boolean;
          payload: { title?: string | null; html: string };
      }
    | { type: 'table'; enabled: boolean; payload: TablePayload }
    | {
          type: 'license';
          enabled: boolean;
          payload: { title?: string | null; items: LicenseItem[] };
      }
    | { type: 'price_badge'; enabled: boolean; payload: { label: string } };

export type SectionType = SectionRow['type'];

export const sectionLabels: Record<SectionType, string> = {
    items: 'Позиции',
    stages: 'Этапы работ',
    custom: 'Текстовый блок',
    table: 'Произвольная таблица',
    license: 'Лицензии и расходы',
    price_badge: 'Примечание по цене',
};

/** Короткие пояснения для меню «Добавить блок» и шапки карточки. */
export const sectionHints: Record<SectionType, string> = {
    items: 'Таблица работ с количеством, ценами и итогом',
    stages: 'Пронумерованный список этапов со сроками',
    custom: 'Форматированный текст: заголовки, списки, таблицы',
    table: 'Своя таблица с колонками, строками и итогом',
    license: 'Периодические платежи: лицензии, подписки',
    price_badge: 'Заметная плашка с примечанием о стоимости',
};

function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

/**
 * Legacy Editor.js-блоки → HTML для WYSIWYG-редактора. Inline-HTML в текстах
 * сохраняется как есть — на выходе его всё равно санитизирует сервер.
 */
export function blocksToHtml(blocks: unknown): string {
    if (!Array.isArray(blocks)) return '';

    return blocks
        .map((block) => {
            if (typeof block !== 'object' || block === null) return '';
            const { type, data } = block as {
                type?: string;
                data?: Record<string, unknown>;
            };
            if (!data) return '';

            if (type === 'header') {
                const level = Math.min(Math.max(Number(data.level ?? 2), 1), 3);
                const text = typeof data.text === 'string' ? data.text : '';
                return text.trim() === ''
                    ? ''
                    : `<h${level}>${escapeHtml(text)}</h${level}>`;
            }

            if (type === 'list') {
                const items = Array.isArray(data.items) ? data.items : [];
                if (items.length === 0) return '';
                const tag = data.style === 'ordered' ? 'ol' : 'ul';
                const body = items
                    .map((item) => `<li>${escapeHtml(String(item))}</li>`)
                    .join('');
                return `<${tag}>${body}</${tag}>`;
            }

            if (type === 'table') {
                const rows = Array.isArray(data.content) ? data.content : [];
                if (rows.length === 0) return '';
                const withHeadings = Boolean(data.withHeadings);
                const body = rows
                    .map((row, rowIndex) => {
                        if (!Array.isArray(row)) return '';
                        const cellTag =
                            withHeadings && rowIndex === 0 ? 'th' : 'td';
                        return `<tr>${row.map((cell) => `<${cellTag}>${escapeHtml(String(cell))}</${cellTag}>`).join('')}</tr>`;
                    })
                    .join('');
                return `<table>${body}</table>`;
            }

            const text = typeof data.text === 'string' ? data.text : '';
            return text.trim() === '' ? '' : `<p>${escapeHtml(text)}</p>`;
        })
        .filter(Boolean)
        .join('');
}

const PASTE_ALLOWED_TAGS = new Set([
    'P',
    'DIV',
    'BR',
    'B',
    'STRONG',
    'I',
    'EM',
    'U',
    'CODE',
    'H1',
    'H2',
    'H3',
    'UL',
    'OL',
    'LI',
    'TABLE',
    'THEAD',
    'TBODY',
    'TR',
    'TH',
    'TD',
]);

/**
 * Очистка вставляемого HTML (Word, Google Docs): allowlist тегов, снятие
 * всех атрибутов (кроме безопасного href у ссылок), разворачивание остальных
 * тегов с сохранением текста. Зеркалит серверный санитайзер рендерера.
 */
export function sanitizePastedHtml(html: string): string {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const schemeOk = (url: string): boolean =>
        /^(https?|mailto|tel):/i.test(url.trim());

    const clean = (node: Element): void => {
        for (const child of Array.from(node.children)) {
            if (!PASTE_ALLOWED_TAGS.has(child.tagName)) {
                // Разворачиваем неизвестный тег: оставляем содержимое на его месте
                while (child.firstChild)
                    node.insertBefore(child.firstChild, child);
                child.remove();
                continue;
            }

            for (const attr of Array.from(child.attributes)) {
                if (
                    child.tagName === 'A' &&
                    attr.name === 'href' &&
                    schemeOk(attr.value)
                )
                    continue;
                child.removeAttribute(attr.name);
            }

            if (
                child.tagName === 'A' &&
                !Array.from(child.attributes).some((a) => a.name === 'href')
            ) {
                while (child.firstChild)
                    node.insertBefore(child.firstChild, child);
                child.remove();
                continue;
            }

            clean(child);
        }
    };

    clean(doc.body);
    return doc.body.innerHTML;
}

/** Есть ли в секции пользовательский контент (для подтверждения удаления). */
export function sectionHasContent(section: SectionRow): boolean {
    switch (section.type) {
        case 'items':
            return section.payload.items.some(
                (item) =>
                    item.name.trim() !== '' ||
                    (item.unit_price.trim() !== '' &&
                        item.unit_price !== '0.00'),
            );
        case 'stages':
            return section.payload.stages.some(
                (stage) =>
                    stage.title.trim() !== '' || stage.duration.trim() !== '',
            );
        case 'custom': {
            const text = section.payload.html.replace(/<[^>]*>/g, '').trim();
            return text !== '' || section.payload.html.includes('<table');
        }
        case 'table': {
            const columns = section.payload.columns;
            const rows = section.payload.rows;
            return (
                columns.length > 1 ||
                (columns[0]?.label ?? '') !== 'Наименование' ||
                rows.some((row) => row.some((cell) => cell.trim() !== ''))
            );
        }
        case 'license':
            return section.payload.items.some(
                (item) =>
                    item.name.trim() !== '' ||
                    item.price.trim() !== '' ||
                    item.price !== '0.00',
            );
        case 'price_badge':
            return section.payload.label.trim() !== '';
    }
}

/** Уникальный технический ключ новой колонки; существующие ключи не трогаем. */
export function generateColumnKey(columns: TableColumn[]): string {
    let n = columns.length + 1;
    while (columns.some((column) => column.key === `col_${n}`)) n += 1;
    return `col_${n}`;
}

/** Ошибки конкретной секции из плоского объекта ошибок формы. */
export function extractSectionErrors(
    errors: Record<string, string>,
    index: number,
): Record<string, string> {
    const prefix = `sections.${index}.payload.`;
    const result: Record<string, string> = {};
    for (const [key, message] of Object.entries(errors)) {
        if (key.startsWith(prefix)) result[key.slice(prefix.length)] = message;
    }
    return result;
}

/** Строка-деньги в копейках; нечисловые ячейки пропускаются (как на сервере). */
export function decimalToCents(value: string): number | null {
    if (!/^\d+([.,]\d{1,2})?$/.test(value.trim())) return null;
    const [rubles, kopecks = ''] = value.trim().replace(',', '.').split('.');
    return (
        parseInt(rubles, 10) * 100 + parseInt((kopecks + '00').slice(0, 2), 10)
    );
}

/** Клиентский предпросмотр суммы строки (не доверенные значения, §7.2). */
export function lineAmount(quantity: string, price: string): number {
    return Math.round(
        parseFloat(quantity.replace(',', '.')) * (decimalToCents(price) ?? 0),
    );
}
