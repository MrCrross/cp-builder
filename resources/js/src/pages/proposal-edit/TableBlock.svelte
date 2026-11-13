<script lang="ts">
    import { tick } from 'svelte';
    import { Button } from '@/shared/ui/button';
    import { confirmDialog } from '@/shared/ui/confirm-dialog';
    import { Checkbox } from '@/shared/ui/checkbox';
    import { cn } from '@/shared/lib/utils';
    import { formatMoney } from '@/shared/api';
    import { decimalToCents, generateColumnKey, type TableColumn, type TableColumnType, type TablePayload } from './blocks';
    import EllipsisVertical from '@lucide/svelte/icons/ellipsis-vertical';
    import Plus from '@lucide/svelte/icons/plus';
    import Check from '@lucide/svelte/icons/check';

    /**
     * Блок «Произвольная таблица»: визуальный редактор. Технические ключи
     * колонок генерируются автоматически и не показываются; названия
     * редактируются прямо в шапке; тип колонки влияет только на ввод
     * (значения остаются строками — данные не теряются при смене типа).
     */
    let {
        payload = $bindable<TablePayload>({ title: null, columns: [], rows: [], price_column: null, include_in_total: false }),
        canEdit = true,
        errors = {},
    }: {
        payload?: TablePayload;
        canEdit?: boolean;
        errors?: Record<string, string>;
    } = $props();

    const columnTypeLabels: Record<TableColumnType, string> = {
        text: 'Текст',
        number: 'Число',
        money: 'Деньги',
    };

    /** Открытое меню строки/колонки (fixed-позиционирование: не обрезается скроллом). */
    let menu: { x: number; y: number; kind: 'column' | 'row'; index: number } | null = $state(null);
    let labelInputs: (HTMLInputElement | undefined)[] = $state([]);

    const priceIndex = $derived(payload.columns.findIndex((column) => column.key === payload.price_column));
    const showTotal = $derived(payload.include_in_total && priceIndex !== -1);
    const totalCents = $derived.by(() => {
        if (!showTotal) return 0;
        let sum = 0;
        for (const row of payload.rows) {
            const cents = decimalToCents(row[priceIndex] ?? '');
            if (cents !== null) sum += cents;
        }
        return sum;
    });
    const totalDecimal = $derived(`${Math.floor(totalCents / 100)}.${String(totalCents % 100).padStart(2, '0')}`);

    // -- Колонки -----------------------------------------------------------------

    function addColumn() {
        const column: TableColumn = { key: generateColumnKey(payload.columns), label: '', type: 'text' };
        payload = { ...payload, columns: [...payload.columns, column] };
        payload = { ...payload, rows: payload.rows.map((row) => [...row, '']) };
        void tick().then(() => labelInputs[payload.columns.length - 1]?.focus());
    }

    function setColumnLabel(index: number, value: string) {
        payload = {
            ...payload,
            columns: payload.columns.map((column, i) => (i === index ? { ...column, label: value } : column)),
        };
    }

    function setColumnType(index: number, type: TableColumnType) {
        payload = {
            ...payload,
            columns: payload.columns.map((column, i) => (i === index ? { ...column, type } : column)),
        };
    }

    function togglePriceColumn(index: number) {
        const column = payload.columns[index];
        const isPrice = payload.price_column === column.key;
        payload = {
            ...payload,
            price_column: isPrice ? null : column.key,
            columns: isPrice
                ? payload.columns
                : payload.columns.map((c, i) => (i === index ? { ...c, type: 'money' } : c)),
        };
    }

    function moveColumn(index: number, delta: number) {
        const target = index + delta;
        if (target < 0 || target >= payload.columns.length) return;
        const columns = [...payload.columns];
        [columns[index], columns[target]] = [columns[target], columns[index]];
        const rows = payload.rows.map((row) => {
            const next = [...row];
            [next[index], next[target]] = [next[target], next[index]];
            return next;
        });
        payload = { ...payload, columns, rows };
    }

    async function deleteColumn(index: number) {
        const column = payload.columns[index];
        const hasData =
            column.label.trim() !== '' || payload.rows.some((row) => (row[index] ?? '').trim() !== '');
        if (hasData) {
            const ok = await confirmDialog({
                title: `Удалить колонку «${column.label || 'без названия'}»?`,
                description: 'Данные в ней будут потеряны.',
                variant: 'danger',
                confirmText: 'Удалить',
            });
            if (!ok) return;
        }
        payload = {
            ...payload,
            columns: payload.columns.filter((_, i) => i !== index),
            rows: payload.rows.map((row) => row.filter((_, i) => i !== index)),
            price_column: payload.price_column === column.key ? null : payload.price_column,
        };
    }

    // -- Строки ------------------------------------------------------------------

    function addRow() {
        payload = { ...payload, rows: [...payload.rows, payload.columns.map(() => '')] };
    }

    function setCell(rowIndex: number, colIndex: number, value: string) {
        const type = payload.columns[colIndex]?.type ?? 'text';
        let next = value;
        if (type === 'number' || type === 'money') {
            next = value.replace(/[^\d.,]/g, '');
        }
        if (type === 'money') {
            next = next.replace(',', '.');
        }
        const rows = payload.rows.map((row, i) => (i === rowIndex ? row.map((cell, j) => (j === colIndex ? next : cell)) : row));
        payload = { ...payload, rows };
    }

    function duplicateRow(index: number) {
        payload = {
            ...payload,
            rows: [...payload.rows.slice(0, index + 1), [...payload.rows[index]], ...payload.rows.slice(index + 1)],
        };
    }

    function moveRow(index: number, delta: number) {
        const target = index + delta;
        if (target < 0 || target >= payload.rows.length) return;
        const rows = [...payload.rows];
        [rows[index], rows[target]] = [rows[target], rows[index]];
        payload = { ...payload, rows };
    }

    async function deleteRow(index: number) {
        const filled = payload.rows[index].some((cell) => cell.trim() !== '');
        if (filled) {
            const ok = await confirmDialog({
                title: 'Удалить строку?',
                description: 'Введённые в ней данные будут потеряны.',
                variant: 'danger',
                confirmText: 'Удалить',
            });
            if (!ok) return;
        }
        payload = { ...payload, rows: payload.rows.filter((_, i) => i !== index) };
    }

    // -- Меню ----------------------------------------------------------------------

    function openMenu(event: MouseEvent, kind: 'column' | 'row', index: number) {
        const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
        menu = { x: Math.min(rect.left, window.innerWidth - 240), y: rect.bottom + 4, kind, index };
    }

    function closeMenu() {
        menu = null;
    }

    function renameColumn(index: number) {
        closeMenu();
        void tick().then(() => {
            labelInputs[index]?.focus();
            labelInputs[index]?.select();
        });
    }

    function onWindowPointerDown(event: PointerEvent) {
        if (menu && !(event.target as HTMLElement).closest('[data-table-menu]')) closeMenu();
    }
</script>

<svelte:window onpointerdown={onWindowPointerDown} />

<div class="space-y-3" data-test="table-editor">
    {#if payload.columns.length === 0}
        <div class="rounded-md border border-dashed border-line px-4 py-8 text-center">
            <p class="text-sm text-muted-foreground">Таблица пока пуста.</p>
            {#if canEdit}
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="mt-3"
                    data-test="seed-table"
                    onclick={() => {
                        payload = { ...payload, columns: [{ key: 'col_1', label: 'Наименование', type: 'text' }], rows: [['']] };
                    }}
                >
                    <Plus class="size-4" />
                    Создать таблицу
                </Button>
            {/if}
        </div>
    {:else}
        <div class="overflow-x-auto rounded-md border border-line">
            <table class="w-full min-w-max border-collapse text-sm">
                <thead>
                    <tr class="border-b border-line">
                        {#each payload.columns as column, columnIndex (column.key)}
                            <th
                                class={cn(
                                    'sticky top-0 min-w-36 bg-muted/60 p-0 text-left align-top font-medium',
                                    columnIndex === 0 && 'left-0 z-30',
                                )}
                            >
                                <div class="flex items-start gap-0.5 px-2 pt-2">
                                    <div class="grid flex-1 gap-0.5">
                                        <input
                                            bind:this={labelInputs[columnIndex]}
                                            value={column.label}
                                            oninput={(event) => setColumnLabel(columnIndex, (event.target as HTMLInputElement).value)}
                                            placeholder="Название колонки"
                                            aria-label="Название колонки {columnIndex + 1}"
                                            aria-invalid={Boolean(errors[`columns.${columnIndex}.label`])}
                                            disabled={!canEdit}
                                            class={cn(
                                                'h-8 w-full rounded-md border border-transparent bg-transparent px-2 text-sm font-medium outline-none transition-colors placeholder:font-normal placeholder:text-muted-foreground focus:border-ring focus:bg-background disabled:cursor-not-allowed disabled:opacity-50',
                                                errors[`columns.${columnIndex}.label`] && 'border-destructive',
                                            )}
                                            data-test="table-column-label"
                                        />
                                        {#if errors[`columns.${columnIndex}.label`]}
                                            <span class="text-xs font-normal text-destructive">{errors[`columns.${columnIndex}.label`]}</span>
                                        {/if}
                                    </div>
                                    {#if canEdit}
                                        <button
                                            type="button"
                                            class="rounded p-1 text-muted-foreground hover:bg-muted"
                                            title="Настройки колонки"
                                            aria-label="Настройки колонки {columnIndex + 1}"
                                            aria-haspopup="menu"
                                            aria-expanded={menu?.kind === 'column' && menu.index === columnIndex}
                                            onclick={(event) => openMenu(event, 'column', columnIndex)}
                                        >
                                            <EllipsisVertical class="size-4" />
                                        </button>
                                    {/if}
                                </div>
                                <div class="px-2 pb-1.5 text-[11px] font-normal text-muted-foreground">
                                    {columnTypeLabels[column.type]}{column.key === payload.price_column ? ' · ценовая' : ''}
                                </div>
                            </th>
                        {/each}
                        <th class="sticky top-0 right-0 w-10 bg-muted/60" aria-label="Действия со строками"></th>
                    </tr>
                </thead>
                <tbody>
                    {#each payload.rows as row, rowIndex (rowIndex)}
                        <tr class="border-b border-line last:border-0" data-test="table-row">
                            {#each payload.columns as column, columnIndex (column.key)}
                                <td class={cn('p-0', columnIndex === 0 && 'sticky left-0 z-10 bg-background')}>
                                    <input
                                        value={row[columnIndex] ?? ''}
                                        oninput={(event) => setCell(rowIndex, columnIndex, (event.target as HTMLInputElement).value)}
                                        inputmode={column.type === 'text' ? 'text' : 'decimal'}
                                        aria-label="Строка {rowIndex + 1}, колонка {column.label || columnIndex + 1}"
                                        placeholder="—"
                                        disabled={!canEdit}
                                        class={cn(
                                            'w-full min-w-32 bg-transparent px-2 py-1.5 text-sm outline-none placeholder:text-muted-foreground/50 focus:bg-muted/40',
                                            (column.type === 'number' || column.type === 'money') && 'text-right tabular-nums',
                                        )}
                                        data-test="table-cell"
                                    />
                                </td>
                            {/each}
                            <td class="sticky right-0 w-10 bg-background p-0 text-center">
                                {#if canEdit}
                                    <button
                                        type="button"
                                        class="rounded p-1 text-muted-foreground hover:bg-muted"
                                        title="Действия со строкой"
                                        aria-label="Действия со строкой {rowIndex + 1}"
                                        aria-haspopup="menu"
                                        aria-expanded={menu?.kind === 'row' && menu.index === rowIndex}
                                        onclick={(event) => openMenu(event, 'row', rowIndex)}
                                    >
                                        <EllipsisVertical class="size-4" />
                                    </button>
                                {/if}
                            </td>
                        </tr>
                    {:else}
                        <tr>
                            <td colspan={payload.columns.length + 1} class="px-3 py-4 text-center text-muted-foreground">
                                Строк пока нет — добавьте первую кнопкой ниже.
                            </td>
                        </tr>
                    {/each}
                </tbody>
                {#if showTotal}
                    <tfoot>
                        <tr class="border-t border-line bg-muted/40 font-medium">
                            {#each payload.columns as column, columnIndex (column.key)}
                                <td class={cn('px-2 py-1.5 tabular-nums', columnIndex === 0 && 'sticky left-0 bg-muted')}>
                                    {columnIndex === priceIndex ? `Итого: ${formatMoney(totalDecimal)}` : ''}
                                </td>
                            {/each}
                            <td class="sticky right-0 bg-muted"></td>
                        </tr>
                    </tfoot>
                {/if}
            </table>
        </div>
        {#if errors['columns']}
            <p class="text-xs text-destructive">{errors['columns']}</p>
        {/if}

        {#if canEdit}
            <div class="flex flex-wrap gap-2">
                <Button type="button" size="sm" variant="outline" onclick={addRow} data-test="add-table-row">
                    <Plus class="size-4" />
                    Добавить строку
                </Button>
                <Button type="button" size="sm" variant="outline" onclick={addColumn} data-test="add-table-column">
                    <Plus class="size-4" />
                    Добавить колонку
                </Button>
            </div>
        {/if}
    {/if}

    <div class="flex items-start gap-2 rounded-md border border-line bg-muted/30 px-3 py-2.5">
        <Checkbox
            id="table-include-in-total"
            bind:checked={payload.include_in_total}
            disabled={!canEdit || priceIndex === -1}
            aria-label="Включать таблицу в итог"
        />
        <div class="grid gap-0.5">
            <label for="table-include-in-total" class="cursor-pointer text-sm font-medium">Включать в итог</label>
            <p class="text-xs text-muted-foreground">
                {#if priceIndex !== -1}
                    В итог документа добавится сумма колонки «{payload.columns[priceIndex].label || 'без названия'}»{showTotal ? `: ${formatMoney(totalDecimal)} (окончательный считает сервер)` : ''}.
                {:else}
                    Чтобы включить расчёт, отметьте ценовую колонку в меню колонки (кнопка ⋯ в шапке).
                {/if}
            </p>
        </div>
    </div>
</div>

{#if menu}
    {@const column = menu.kind === 'column' ? payload.columns[menu.index] : undefined}
    <div
        class="fixed z-50 w-60 rounded-md border border-line bg-popover p-1 text-sm shadow-md"
        style="left: {menu.x}px; top: {menu.y}px;"
        role="menu"
        tabindex="-1"
        data-table-menu
        onkeydown={(event) => event.key === 'Escape' && closeMenu()}
    >
        {#if menu.kind === 'column' && column}
            <button type="button" class="flex w-full items-center rounded-sm px-2 py-1.5 hover:bg-muted" role="menuitem" onclick={() => renameColumn(menu!.index)}>
                Переименовать
            </button>
            <div class="px-2 pb-1 pt-2 text-xs font-medium text-muted-foreground">Тип содержимого</div>
            {#each Object.entries(columnTypeLabels) as [type, typeLabel] (type)}
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-sm px-2 py-1.5 hover:bg-muted"
                    role="menuitem"
                    onclick={() => {
                        setColumnType(menu!.index, type as TableColumnType);
                        closeMenu();
                    }}
                >
                    {typeLabel}
                    {#if column.type === type}<Check class="size-4" />{/if}
                </button>
            {/each}
            <button
                type="button"
                class="flex w-full items-center justify-between rounded-sm px-2 py-1.5 hover:bg-muted"
                role="menuitem"
                onclick={() => {
                    togglePriceColumn(menu!.index);
                    closeMenu();
                }}
            >
                Ценовая колонка
                {#if payload.price_column === column.key}<Check class="size-4" />{/if}
            </button>
            <div class="my-1 h-px bg-border"></div>
            <button type="button" class="flex w-full items-center rounded-sm px-2 py-1.5 hover:bg-muted disabled:opacity-40" role="menuitem" disabled={menu.index === 0} onclick={() => { moveColumn(menu!.index, -1); closeMenu(); }}>
                Переместить влево
            </button>
            <button type="button" class="flex w-full items-center rounded-sm px-2 py-1.5 hover:bg-muted disabled:opacity-40" role="menuitem" disabled={menu.index === payload.columns.length - 1} onclick={() => { moveColumn(menu!.index, 1); closeMenu(); }}>
                Переместить вправо
            </button>
            <button
                type="button"
                class="flex w-full items-center rounded-sm px-2 py-1.5 text-destructive hover:bg-destructive/10"
                role="menuitem"
                onclick={() => {
                    deleteColumn(menu!.index);
                    closeMenu();
                }}
            >
                Удалить колонку
            </button>
        {:else if menu.kind === 'row'}
            <button type="button" class="flex w-full items-center rounded-sm px-2 py-1.5 hover:bg-muted" role="menuitem" onclick={() => { duplicateRow(menu!.index); closeMenu(); }}>
                Дублировать строку
            </button>
            <button type="button" class="flex w-full items-center rounded-sm px-2 py-1.5 hover:bg-muted disabled:opacity-40" role="menuitem" disabled={menu.index === 0} onclick={() => { moveRow(menu!.index, -1); closeMenu(); }}>
                Переместить выше
            </button>
            <button type="button" class="flex w-full items-center rounded-sm px-2 py-1.5 hover:bg-muted disabled:opacity-40" role="menuitem" disabled={menu.index === payload.rows.length - 1} onclick={() => { moveRow(menu!.index, 1); closeMenu(); }}>
                Переместить ниже
            </button>
            <div class="my-1 h-px bg-border"></div>
            <button
                type="button"
                class="flex w-full items-center rounded-sm px-2 py-1.5 text-destructive hover:bg-destructive/10"
                role="menuitem"
                onclick={() => {
                    deleteRow(menu!.index);
                    closeMenu();
                }}
            >
                Удалить строку
            </button>
        {/if}
    </div>
{/if}
