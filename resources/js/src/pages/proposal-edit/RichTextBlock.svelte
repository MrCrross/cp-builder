<script lang="ts">
    import { onMount, type Component } from 'svelte';
    import { cn } from '@/shared/lib/utils';
    import { sanitizePastedHtml } from './blocks';
    import Bold from '@lucide/svelte/icons/bold';
    import Italic from '@lucide/svelte/icons/italic';
    import Underline from '@lucide/svelte/icons/underline';
    import List from '@lucide/svelte/icons/list';
    import ListOrdered from '@lucide/svelte/icons/list-ordered';
    import AlignLeft from '@lucide/svelte/icons/align-left';
    import AlignCenter from '@lucide/svelte/icons/align-center';
    import AlignRight from '@lucide/svelte/icons/align-right';
    import Link from '@lucide/svelte/icons/link';
    import Table from '@lucide/svelte/icons/table';
    import Undo2 from '@lucide/svelte/icons/undo-2';
    import Redo2 from '@lucide/svelte/icons/redo-2';
    import RemoveFormatting from '@lucide/svelte/icons/remove-formatting';

    /**
     * Визуальный редактор форматированного текста (WYSIWYG) для блока
     * «Текстовый блок». Результат — HTML-строка, которую на сервере
     * санитизирует EditorJsRenderer::renderHtml. Пользователь не видит
     * разметки: форматирование применяется к видимому тексту сразу.
     */
    let {
        html = $bindable(''),
        canEdit = true,
    }: {
        html?: string;
        canEdit?: boolean;
    } = $props();

    let editor: HTMLDivElement | undefined = $state();
    /** Последнее значение, записанное в редактор извне (защита от затирания курсора). */
    let syncedHtml = '';
    let blockType = $state('p');
    let marks = $state({ bold: false, italic: false, underline: false, unordered: false, ordered: false });
    let activeCell: { table: HTMLTableElement; row: number; col: number } | null = $state(null);
    let linkOpen = $state(false);
    let linkUrl = $state('');
    let tableOpen = $state(false);
    let tableRows = $state(2);
    let tableCols = $state(2);
    /** Выделение в редакторе на момент ухода фокуса в панель — для возврата. */
    let savedRange: Range | null = null;

    $effect(() => {
        if (editor && html !== syncedHtml) {
            editor.innerHTML = html;
            syncedHtml = html;
        }
    });

    function saveRange() {
        const selection = document.getSelection();
        if (selection && selection.rangeCount > 0 && editor?.contains(selection.anchorNode)) {
            savedRange = selection.getRangeAt(0).cloneRange();
        }
    }

    function restoreRange() {
        if (!savedRange) return;
        const selection = document.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(savedRange);
    }

    function refreshState() {
        const raw = (document.queryCommandValue('formatBlock') || 'p').toLowerCase();
        blockType = ['h1', 'h2', 'h3'].includes(raw) ? raw : 'p';
        marks = {
            bold: document.queryCommandState('bold'),
            italic: document.queryCommandState('italic'),
            underline: document.queryCommandState('underline'),
            unordered: document.queryCommandState('insertUnorderedList'),
            ordered: document.queryCommandState('insertOrderedList'),
        };
    }

    function updateActiveCell() {
        const selection = document.getSelection();
        const node = selection?.anchorNode ?? null;
        const element = node instanceof Element ? node : node?.parentElement ?? null;
        const cell = element?.closest('td, th') ?? null;
        const table = cell?.closest('table') ?? null;
        if (cell instanceof HTMLTableCellElement && table instanceof HTMLTableElement && editor?.contains(table)) {
            const rowElement = cell.parentElement as unknown as HTMLTableRowElement | null;
            activeCell = {
                table,
                row: rowElement?.rowIndex ?? 0,
                col: cell.cellIndex,
            };
        } else {
            activeCell = null;
        }
    }

    onMount(() => {
        const handler = () => {
            const selection = document.getSelection();
            if (editor && selection?.anchorNode && editor.contains(selection.anchorNode)) {
                saveRange();
                refreshState();
                updateActiveCell();
            }
        };
        document.addEventListener('selectionchange', handler);
        return () => document.removeEventListener('selectionchange', handler);
    });

    function sync() {
        if (!editor) return;
        html = editor.innerHTML;
        syncedHtml = html;
    }

    function cmd(command: string, value?: string) {
        editor?.focus();
        document.execCommand(command, false, value);
        sync();
    }

    function applyBlockType(event: Event) {
        restoreRange();
        cmd('formatBlock', (event.target as HTMLSelectElement).value);
    }

    function normalizeUrl(raw: string): string | null {
        let url = raw.trim();
        if (url === '') return null;
        if (!/^[a-z][a-z0-9+.-]*:/i.test(url)) url = `https://${url}`;
        return /^(https?|mailto|tel):/i.test(url) ? url : null;
    }

    function applyLink() {
        const url = normalizeUrl(linkUrl);
        if (url === null) {
            if (linkUrl.trim() === '') cmd('unlink');
        } else {
            restoreRange();
            cmd('createLink', url);
        }
        linkOpen = false;
        linkUrl = '';
    }

    function insertTable() {
        const rows = Math.min(Math.max(tableRows, 1), 20);
        const cols = Math.min(Math.max(tableCols, 1), 10);
        let markup = '<table><thead><tr>';
        markup += '<th><br></th>'.repeat(cols);
        markup += '</tr></thead><tbody>';
        for (let r = 1; r < rows; r += 1) {
            markup += `<tr>${'<td><br></td>'.repeat(cols)}</tr>`;
        }
        markup += '</tbody></table><p><br></p>';
        restoreRange();
        cmd('insertHTML', markup);
        tableOpen = false;
    }

    function onPaste(event: ClipboardEvent) {
        event.preventDefault();
        const htmlData = event.clipboardData?.getData('text/html');
        if (htmlData) {
            cmd('insertHTML', sanitizePastedHtml(htmlData));
        } else {
            cmd('insertText', event.clipboardData?.getData('text/plain') ?? '');
        }
    }

    function onKeydown(event: KeyboardEvent) {
        // Tab в последней ячейке таблицы добавляет строку — как в текстовых редакторов
        if (event.key === 'Tab' && !event.shiftKey && activeCell) {
            const { table, row, col } = activeCell;
            if (row === table.rows.length - 1 && col === table.rows[row].cells.length - 1) {
                event.preventDefault();
                addTableRow(1);
            }
        }
    }

    // -- Операции над активной таблицей --------------------------------------

    function makeCell(tag: string): HTMLElement {
        const cell = document.createElement(tag);
        cell.innerHTML = '<br>';
        return cell;
    }

    function addTableRow(offset: number) {
        if (!activeCell) return;
        const { table, row } = activeCell;
        const reference = table.rows[row];
        if (!reference) return;
        const newRow = table.insertRow(row + offset);
        for (const refCell of Array.from(reference.cells)) {
            newRow.appendChild(makeCell(refCell.tagName.toLowerCase()));
        }
        sync();
    }

    function deleteTableRow() {
        if (!activeCell) return;
        const { table, row } = activeCell;
        if (table.rows.length <= 1) {
            table.remove();
            activeCell = null;
        } else {
            table.deleteRow(row);
        }
        sync();
    }

    function addTableColumn(offset: number) {
        if (!activeCell) return;
        const { table, col } = activeCell;
        for (const row of Array.from(table.rows)) {
            const refCell = row.cells[col] ?? row.cells[row.cells.length - 1];
            const cell = makeCell(refCell ? refCell.tagName.toLowerCase() : 'td');
            row.insertBefore(cell, row.cells[col + offset] ?? null);
        }
        sync();
    }

    function deleteTableColumn() {
        if (!activeCell) return;
        const { table, col } = activeCell;
        for (const row of Array.from(table.rows)) {
            if (row.cells.length > 1) row.deleteCell(col);
        }
        if (Array.from(table.rows).every((row) => row.cells.length === 0)) table.remove();
        activeCell = null;
        sync();
    }

    // -- Панель инструментов ---------------------------------------------------

    const toolClass = (isActive: boolean) =>
        cn(
            'rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            isActive && 'bg-muted text-foreground',
        );
</script>

{#snippet tool(Icon: Component, title: string, onclick: () => void, isActive = false)}
    <button
        type="button"
        {title}
        aria-label={title}
        aria-pressed={isActive}
        class={toolClass(isActive)}
        onmousedown={(event) => event.preventDefault()}
        {onclick}
    >
        <Icon class="size-4" />
    </button>
{/snippet}

{#snippet divider()}
    <span class="mx-1 h-5 w-px bg-border" aria-hidden="true"></span>
{/snippet}

<div class="space-y-2" data-test="rich-text-editor">
    {#if canEdit}
        <div class="relative flex flex-wrap items-center gap-0.5 rounded-md border border-line bg-muted/30 p-1" role="toolbar" aria-label="Форматирование текста">
            <select
                value={blockType}
                onmousedown={saveRange}
                onchange={applyBlockType}
                aria-label="Тип текста"
                title="Тип текста"
                class="rounded-md border border-line bg-background px-2 py-1.5 text-sm"
            >
                <option value="p">Обычный текст</option>
                <option value="h1">Заголовок 1</option>
                <option value="h2">Заголовок 2</option>
                <option value="h3">Заголовок 3</option>
            </select>

            {@render divider()}
            {@render tool(Bold, 'Жирный', () => cmd('bold'), marks.bold)}
            {@render tool(Italic, 'Курсив', () => cmd('italic'), marks.italic)}
            {@render tool(Underline, 'Подчёркнутый', () => cmd('underline'), marks.underline)}
            {@render tool(RemoveFormatting, 'Очистить форматирование', () => cmd('removeFormat'))}

            {@render divider()}
            {@render tool(List, 'Маркированный список', () => cmd('insertUnorderedList'), marks.unordered)}
            {@render tool(ListOrdered, 'Нумерованный список', () => cmd('insertOrderedList'), marks.ordered)}
            {@render tool(AlignLeft, 'По левому краю', () => cmd('justifyLeft'))}
            {@render tool(AlignCenter, 'По центру', () => cmd('justifyCenter'))}
            {@render tool(AlignRight, 'По правому краю', () => cmd('justifyRight'))}

            {@render divider()}
            <div class="relative">
                <button
                    type="button"
                    title="Добавить ссылку"
                    aria-label="Добавить ссылку"
                    aria-expanded={linkOpen}
                    class={toolClass(linkOpen)}
                    onmousedown={(event) => event.preventDefault()}
                    onclick={() => {
                        saveRange();
                        linkOpen = !linkOpen;
                        tableOpen = false;
                    }}
                >
                    <Link class="size-4" />
                </button>
                {#if linkOpen}
                    <div class="absolute left-0 top-full z-20 mt-1 w-72 rounded-md border border-line bg-popover p-2 shadow-md">
                        <label class="mb-1 block text-xs text-muted-foreground" for="rte-link-url">Адрес ссылки</label>
                        <input
                            id="rte-link-url"
                            type="text"
                            bind:value={linkUrl}
                            placeholder="https://example.com"
                            class="mb-2 w-full rounded-md border border-line bg-background px-2 py-1.5 text-sm"
                            onkeydown={(event) => event.key === 'Enter' && applyLink()}
                        />
                        <div class="flex gap-2">
                            <button type="button" class="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90" onclick={applyLink}>
                                Применить
                            </button>
                            <button type="button" class="rounded-md px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted" onclick={() => cmd('unlink')}>
                                Убрать ссылку
                            </button>
                        </div>
                    </div>
                {/if}
            </div>
            <div class="relative">
                <button
                    type="button"
                    title="Вставить таблицу"
                    aria-label="Вставить таблицу"
                    aria-expanded={tableOpen}
                    class={toolClass(tableOpen)}
                    onmousedown={(event) => event.preventDefault()}
                    onclick={() => {
                        saveRange();
                        tableOpen = !tableOpen;
                        linkOpen = false;
                    }}
                >
                    <Table class="size-4" />
                </button>
                {#if tableOpen}
                    <div class="absolute left-0 top-full z-20 mt-1 w-64 rounded-md border border-line bg-popover p-2 shadow-md">
                        <p class="mb-2 text-xs text-muted-foreground">Первая строка станет заголовком таблицы.</p>
                        <div class="mb-2 flex gap-2">
                            <label class="grid flex-1 gap-1 text-xs text-muted-foreground">
                                Строк
                                <input type="number" min="1" max="20" bind:value={tableRows} class="rounded-md border border-line bg-background px-2 py-1.5 text-sm" />
                            </label>
                            <label class="grid flex-1 gap-1 text-xs text-muted-foreground">
                                Колонок
                                <input type="number" min="1" max="10" bind:value={tableCols} class="rounded-md border border-line bg-background px-2 py-1.5 text-sm" />
                            </label>
                        </div>
                        <button type="button" class="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:bg-primary/90" onclick={insertTable}>
                            Вставить таблицу
                        </button>
                    </div>
                {/if}
            </div>

            {@render divider()}
            {@render tool(Undo2, 'Отменить', () => cmd('undo'))}
            {@render tool(Redo2, 'Повторить', () => cmd('redo'))}
        </div>
    {/if}

    <div
        bind:this={editor}
        contenteditable={canEdit}
        role="textbox"
        tabindex="0"
        aria-multiline="true"
        aria-label="Текст блока"
        data-placeholder="Введите текст. Выделяйте фрагменты, чтобы применить форматирование."
        class="min-h-32 rounded-md border border-line bg-background px-3 py-2 text-sm empty:before:text-muted-foreground empty:before:content-[attr(data-placeholder)] focus:outline-none focus-visible:ring-2 focus-visible:ring-ring [&_h1]:mb-2 [&_h1]:mt-3 [&_h1]:text-xl [&_h1]:font-semibold [&_h2]:mb-2 [&_h2]:mt-3 [&_h2]:text-lg [&_h2]:font-semibold [&_h3]:mb-1 [&_h3]:mt-2 [&_h3]:text-base [&_h3]:font-semibold [&_li]:mb-0.5 [&_ol]:my-2 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:my-1.5 [&_table]:my-2 [&_table]:w-full [&_table]:border-collapse [&_td]:border [&_td]:border-line [&_td]:px-2 [&_td]:py-1 [&_th]:border [&_th]:border-line [&_th]:bg-muted/50 [&_th]:px-2 [&_th]:py-1 [&_th]:text-left [&_ul]:my-2 [&_ul]:list-disc [&_ul]:pl-6"
        data-test="rich-text-input"
        oninput={sync}
        onpaste={onPaste}
        onkeydown={onKeydown}
    ></div>

    {#if activeCell && canEdit}
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-dashed border-line bg-muted/30 px-3 py-2 text-xs" data-test="table-cell-controls">
            <span class="font-medium text-muted-foreground">Строка:</span>
            <button type="button" class="rounded px-1.5 py-0.5 hover:bg-muted" onclick={() => addTableRow(-1)}>+ выше</button>
            <button type="button" class="rounded px-1.5 py-0.5 hover:bg-muted" onclick={() => addTableRow(1)}>+ ниже</button>
            <button type="button" class="rounded px-1.5 py-0.5 text-destructive hover:bg-destructive/10" onclick={deleteTableRow}>удалить</button>
            <span class="font-medium text-muted-foreground">Колонка:</span>
            <button type="button" class="rounded px-1.5 py-0.5 hover:bg-muted" onclick={() => addTableColumn(-1)}>+ слева</button>
            <button type="button" class="rounded px-1.5 py-0.5 hover:bg-muted" onclick={() => addTableColumn(1)}>+ справа</button>
            <button type="button" class="rounded px-1.5 py-0.5 text-destructive hover:bg-destructive/10" onclick={deleteTableColumn}>удалить</button>
        </div>
    {/if}
</div>
