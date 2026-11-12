<script module lang="ts">
    import { create } from '@/routes/proposals';

    export const layout = {
        breadcrumbs: [
            { title: 'Коммерческие предложения', href: '/proposals' },
            { title: 'Редактор', href: create.url() },
        ],
    };
</script>

<script lang="ts">
    import { onMount } from 'svelte';
    import { Form, page, router } from '@inertiajs/svelte';
    import { store } from '@/routes/proposals';
    import { destroy, issue, archive, newDraft, restore, rotateLink, update } from '@/routes/proposals';
    import { catalogDiff, refreshCatalog } from '@/routes/proposals';
    import { search as catalogSearch } from '@/routes/catalog/items';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import InputError from '@/shared/ui/InputError.svelte';
    import { Badge } from '@/shared/ui/badge';
    import { Button } from '@/shared/ui/button';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import {
        formatMoney,
        type CatalogDiffEntry,
        type CatalogItemOption,
        type IssuedFlash,
        type ProposalEditPayload,
        type ProposalItemInput,
        type ProposalSectionInput,
        type ProposalTemplateOption,
        type ProjectOption,
        type SectionType,
    } from '@/shared/api';

    const proposal = $derived(page.props.proposal as ProposalEditPayload | null);
    const templates = $derived((page.props.templates as ProposalTemplateOption[]) ?? []);
    const projects = $derived((page.props.projects as ProjectOption[]) ?? []);
    const permissions = $derived((page.props.auth?.permissions ?? []) as string[]);

    const canEdit = $derived(permissions.includes('proposals.update'));
    const canIssue = $derived(permissions.includes('proposals.issue'));

    // -- Создание ------------------------------------------------------------
    let templateId: number | '' = $state('');

    // -- Редактор черновика ----------------------------------------------------
    type ItemRow = ProposalItemInput;

    type CustomBlock = {
        type: 'paragraph' | 'header' | 'list' | 'table';
        text: string;
        level: number;
        style: 'ordered' | 'unordered';
        items: string;
        withHeadings: boolean;
        content: string;
    };

    function emptyBlock(type: CustomBlock['type']): CustomBlock {
        return { type, text: '', level: 2, style: 'unordered', items: '', withHeadings: true, content: '' };
    }

    /** Загруженный payload custom-секции → редактируемые блоки. */
    function parseBlocks(payload: Record<string, unknown>): CustomBlock[] {
        const raw = (payload.blocks as { type: string; data: Record<string, unknown> }[]) ?? [];
        return raw.map((block) => {
            const base = emptyBlock('paragraph');
            if (block.type === 'header') {
                return { ...base, type: 'header', text: String(block.data.text ?? ''), level: Number(block.data.level ?? 2) };
            }
            if (block.type === 'list') {
                return {
                    ...base,
                    type: 'list',
                    style: block.data.style === 'ordered' ? 'ordered' : 'unordered',
                    items: Array.isArray(block.data.items) ? block.data.items.map(String).join('\n') : '',
                };
            }
            if (block.type === 'table') {
                const rows = Array.isArray(block.data.content) ? block.data.content : [];
                return {
                    ...base,
                    type: 'table',
                    withHeadings: Boolean(block.data.withHeadings),
                    content: rows.map((row) => (Array.isArray(row) ? row.map(String).join('\t') : '')).join('\n'),
                };
            }
            return { ...base, type: 'paragraph', text: String(block.data.text ?? '') };
        });
    }

    type SectionPayload = {
        title?: string | null;
        label?: string;
        stages?: { title: string; duration: string }[];
        items?: { name: string; period: string; price: string }[];
        columns?: { key: string; label: string }[];
        rows?: string[][];
        price_column?: string | null;
        include_in_total?: boolean;
        [key: string]: unknown;
    };

    interface SectionRow {
        type: SectionType;
        enabled: boolean;
        payload: SectionPayload;
        /** Редактируемое представление для custom-секций. */
        blocks?: CustomBlock[];
    }

    let title = $state('');
    let subtitle = $state<string | null>(null);
    let clientName = $state<string | null>(null);
    let clientContact = $state<string | null>(null);
    let managerName = $state<string | null>(null);
    let managerEmail = $state<string | null>(null);
    let managerPhone = $state<string | null>(null);
    let note = $state<string | null>(null);
    let projectId = $state<number | ''>('');
    let items = $state<ItemRow[]>([]);
    let sections = $state<SectionRow[]>([]);

    $effect(() => {
        const draft = proposal?.draft;
        if (!draft) return;
        title = draft.title;
        subtitle = draft.subtitle;
        clientName = draft.client_name;
        clientContact = draft.client_contact;
        managerName = draft.manager_name;
        managerEmail = draft.manager_email;
        managerPhone = draft.manager_phone;
        note = draft.note;
        items = draft.items.map((i) => ({ ...i }));
        sections = draft.sections.map((s) => ({
            ...s,
            payload: structuredClone(s.payload),
            ...(s.type === 'custom' ? { blocks: parseBlocks(s.payload) } : {}),
        }));
    });

    $effect(() => {
        projectId = proposal?.project_id ?? '';
    });

    // -- Клиентский предпросмотр сумм (не доверенные значения, §7.2) ----------
    function toCents(value: string): number {
        const [r, k = ''] = value.replace(',', '.').split('.');
        return parseInt(r || '0', 10) * 100 + parseInt((k + '00').slice(0, 2), 10);
    }

    function lineAmount(quantity: string, price: string): number {
        // Предпросмотр: half-up на строку; окончательный расчёт — сервер.
        return Math.round(parseFloat(quantity.replace(',', '.')) * toCents(price));
    }

    const previewTotals = $derived.by(() => {
        let min = 0;
        let max = 0;
        for (const item of items) {
            min += lineAmount(item.quantity_min, item.unit_price);
            max += lineAmount(item.quantity_max, item.unit_price);
        }
        const fmt = (cents: number): string => (cents / 100).toFixed(2);
        return { min: fmt(min), max: fmt(max) };
    });

    function addItem() {
        items = [...items, { catalog_item_id: null, name: '', description: null, unit: 'ч', quantity_min: '1', quantity_max: '1', unit_price: '0.00' }];
    }

    // -- Picker номенклатуры -----------------------------------------------------
    let pickerOpen = $state(false);
    let pickerQuery = $state('');
    let pickerResults = $state<CatalogItemOption[]>([]);

    async function runCatalogSearch() {
        const params = new URLSearchParams();
        if (pickerQuery.trim() !== '') params.set('q', pickerQuery.trim());
        const response = await fetch(`${catalogSearch.url()}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;
        const data = (await response.json()) as { items: CatalogItemOption[] };
        pickerResults = data.items;
    }

    function openPicker() {
        pickerOpen = true;
        pickerQuery = '';
        pickerResults = [];
        void runCatalogSearch();
    }

    function pickItem(option: CatalogItemOption) {
        items = [
            ...items,
            {
                catalog_item_id: option.id,
                name: option.name,
                description: option.description,
                unit: option.unit ?? 'шт',
                quantity_min: '1',
                quantity_max: '1',
                unit_price: option.price,
            },
        ];
        pickerOpen = false;
    }

    // -- Обновление связанных строк из справочника (план §7.3) --------------------
    let diffEntries = $state<CatalogDiffEntry[] | null>(null);

    async function showCatalogDiff() {
        if (!proposal) return;
        const response = await fetch(catalogDiff.url(proposal), { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = (await response.json()) as { diff: CatalogDiffEntry[] };
        diffEntries = data.diff;
    }

    function applyCatalogRefresh() {
        if (!proposal) return;
        router.post(refreshCatalog.url(proposal), {}, { preserveScroll: true });
        diffEntries = null;
    }

    function removeItem(index: number) {
        items = items.filter((_, i) => i !== index);
    }

    function moveItem(index: number, delta: number) {
        const target = index + delta;
        if (target < 0 || target >= items.length) return;
        const next = [...items];
        [next[index], next[target]] = [next[target], next[index]];
        items = next;
    }

    // -- Секции ----------------------------------------------------------------
    const sectionLabels: Record<SectionType, string> = {
        stages: 'Этапы работ',
        custom: 'Текстовый блок',
        table: 'Произвольная таблица',
        license: 'Лицензии и расходы',
        price_badge: 'Примечание по цене',
    };

    function addSection(type: SectionType) {
        const payloads: Record<SectionType, Record<string, unknown>> = {
            stages: { title: 'Этапы работ', stages: [{ title: '', duration: '' }] },
            custom: { title: null, blocks: [] },
            table: { title: null, columns: [{ key: 'name', label: 'Наименование' }], rows: [], price_column: null, include_in_total: false },
            license: { title: 'Лицензии и расходы', items: [{ name: '', period: 'в месяц', price: '0.00' }] },
            price_badge: { label: 'Стоимость по запросу' },
        };
        sections = [
            ...sections,
            {
                type,
                enabled: true,
                payload: payloads[type],
                ...(type === 'custom' ? { blocks: [emptyBlock('paragraph')] } : {}),
            },
        ];
    }

    function removeSection(index: number) {
        sections = sections.filter((_, i) => i !== index);
    }

    // -- Одноразовая ссылка ------------------------------------------------------
    // Flash приходит событием router (Inertia отдаёт его один раз и не хранит
    // в history state), а не через page.props — как и тосты в flash-toast.ts.
    onMount(() =>
        router.on('flash', (event) => {
            const issued = (event as CustomEvent<{ flash?: { issued?: IssuedFlash | null } }>).detail?.flash?.issued;

            if (issued) {
                alert(`Публичная ссылка (показывается один раз):\n\n${issued.url}\n\nСкопируйте её сейчас. При утрате — замените ссылку.`);
            }
        }),
    );

    // -- Сериализация -------------------------------------------------------------
    function buildPayload(): Record<string, import('@inertiajs/core').FormDataConvertible> {
        return {
            title,
            subtitle,
            client_name: clientName,
            client_contact: clientContact,
            manager_name: managerName,
            manager_email: managerEmail,
            manager_phone: managerPhone,
            note,
            project_id: projectId === '' ? null : projectId,
            lock_version: proposal?.draft?.lock_version ?? 1,
            items: items.map((item) => ({
                catalog_item_id: item.catalog_item_id,
                name: item.name,
                description: item.description,
                unit: item.unit,
                quantity_min: item.quantity_min,
                quantity_max: item.quantity_max,
                unit_price: item.unit_price,
            })),
            sections: sections.map((section) => ({
                type: section.type,
                enabled: section.enabled,
                payload: normalizePayload(section),
            })),
            // Произвольный вложенный JSON payload — транспорт допускает его,
        } as Record<string, import('@inertiajs/core').FormDataConvertible>;
    }

    function normalizePayload(section: SectionRow): Record<string, unknown> {
        if (section.type === 'custom') {
            const blocks = section.blocks ?? [];
            return {
                title: (section.payload.title as string | null) ?? null,
                blocks: blocks.map((block) => {
                    if (block.type === 'paragraph') return { type: 'paragraph', data: { text: block.text } };
                    if (block.type === 'header') return { type: 'header', data: { text: block.text, level: block.level } };
                    if (block.type === 'list') {
                        return {
                            type: 'list',
                            data: {
                                style: block.style,
                                items: block.items.split('\n').map((s) => s.trim()).filter(Boolean),
                            },
                        };
                    }
                    return {
                        type: 'table',
                        data: {
                            withHeadings: block.withHeadings,
                            content: block.content
                                .split('\n')
                                .filter((line) => line.trim() !== '')
                                .map((line) => line.split('\t')),
                        },
                    };
                }),
            };
        }

        if (section.type === 'table') {
            return {
                title: (section.payload.title as string | null) ?? null,
                columns: (section.payload.columns as { key: string; label: string }[]) ?? [],
                rows: (section.payload.rows as string[][]) ?? [],
                price_column: (section.payload.price_column as string | null) ?? null,
                include_in_total: Boolean(section.payload.include_in_total),
            };
        }

        return section.payload;
    }

    function saveAndIssue() {
        if (!proposal) return;
        if (!confirm('Выпустить КП? Создастся публичный документ и одноразовая ссылка.')) return;

        router.put(update.url(proposal), buildPayload(), {
            preserveScroll: true,
            onError: () => onConflict(),
            onSuccess: () => router.post(issue.url(proposal), {}, { preserveScroll: true }),
        });
    }

    function onConflict() {
        alert('Черновик изменён другим пользователем или уже выпущен. Сейчас страница обновится — скопируйте введённые данные.');
        router.reload();
    }

    /** Произвольная таблица: редактируемые представления по умолчанию. */
    function tableColumnsText(section: SectionRow): string {
        const columns = (section.payload.columns as { key: string; label: string }[]) ?? [];
        return columns.map((c) => `${c.key}:${c.label}`).join('\n');
    }

    function parseTableColumns(text: string): { key: string; label: string }[] {
        return text
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
            .map((line) => {
                const [key = '', ...rest] = line.split(':');
                return { key: key.trim(), label: (rest.join(':') || key).trim() };
            });
    }

    function tableRowsText(section: SectionRow): string {
        const rows = (section.payload.rows as string[][]) ?? [];
        return rows.map((row) => row.map(String).join('\t')).join('\n');
    }
</script>

<AppHead title={proposal ? `КП ${proposal.number ?? ''}` : 'Новое КП'} />

<h1 class="sr-only">Редактор коммерческого предложения</h1>

<div class="flex flex-col space-y-6 p-4">
    <div class="flex items-start justify-between gap-4">
        <Heading
            variant="small"
            title={proposal ? `КП ${proposal.number ?? 'без номера'}` : 'Новое коммерческое предложение'}
            description={proposal?.issued ? `Действующая версия v${proposal.issued.version}.` : 'Черновик не публикуется, пока не выпущен.'}
        />
        {#if proposal}
            <div class="flex items-center gap-2">
                <Badge variant={proposal.status === 'issued' ? 'default' : 'secondary'}>
                    {proposal.status === 'draft' ? 'Черновик' : proposal.status === 'issued' ? 'Выпущено' : 'Архив'}
                </Badge>
                <Button variant="outline" href={proposal.preview_url} target="_blank">Просмотр</Button>
            </div>
        {/if}
    </div>

    {#if !proposal}
        <div class="flex max-w-md items-end gap-2">
            <div class="grid flex-1 gap-2">
                <Label for="template">Шаблон</Label>
                <select id="template" bind:value={templateId} class="rounded-md border border-line bg-background px-3 py-2 text-sm" data-test="template-select">
                    <option value="">Без шаблона</option>
                    {#each templates as template (template.id)}
                        <option value={template.id}>{template.name}</option>
                    {/each}
                </select>
            </div>
            <Form {...store.form()}>
                {#snippet children({ processing })}
                    <input type="hidden" name="template_id" value={templateId} />
                    <Button type="submit" disabled={processing} data-test="create-draft">Создать черновик</Button>
                {/snippet}
            </Form>
        </div>
    {:else if !proposal.draft}
        <div class="space-y-4 rounded-lg border border-line p-6">
            {#if proposal.issued}
                <p class="text-sm text-muted-foreground">
                    Действующая версия v{proposal.issued.version}. Публичная ссылка активна
                    (URL показывался один раз при выпуске; при утрате — замените ссылку).
                </p>
                <div class="flex gap-2">
                    {#if canEdit && proposal.status === 'issued'}
                        <Button onclick={() => { if (confirm('Создать новый черновик из копии текущей версии?')) router.post(newDraft.url(proposal), {}); }}>
                            Новая версия
                        </Button>
                    {/if}
                    {#if proposal.status === 'issued'}
                        <Button variant="outline" onclick={() => { if (confirm('Заменить публичную ссылку? Старая перестанет работать.')) router.post(rotateLink.url(proposal), {}); }}>
                            Заменить ссылку
                        </Button>
                        <Button variant="ghost" onclick={() => { if (confirm('Архивировать КП? Ссылка будет закрыта.')) router.post(archive.url(proposal), {}); }}>
                            В архив
                        </Button>
                    {:else if proposal.status === 'archived'}
                        <Button variant="outline" onclick={() => router.post(restore.url(proposal), {})}>Восстановить</Button>
                    {/if}
                </div>
            {/if}
        </div>
    {:else}
        <Form
            {...update.form(proposal)}
            options={{ preserveScroll: true }}
            transform={(data) => ({ ...data, ...buildPayload() })}
        >
            {#snippet children({ errors, processing })}
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2 md:col-span-2">
                        <Label for="title">Название документа</Label>
                        <Input id="title" name="title" bind:value={title} required maxlength={500} />
                        <InputError message={errors.title} />
                    </div>
                    <div class="grid gap-2 md:col-span-2">
                        <Label for="subtitle">Подзаголовок</Label>
                        <Input id="subtitle" name="subtitle" bind:value={subtitle} maxlength={500} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="client_name">Клиент</Label>
                        <Input id="client_name" name="client_name" bind:value={clientName} maxlength={255} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="client_contact">Контакт клиента</Label>
                        <Input id="client_contact" name="client_contact" bind:value={clientContact} maxlength={255} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="manager_name">Менеджер</Label>
                        <Input id="manager_name" name="manager_name" bind:value={managerName} maxlength={255} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="manager_email">Email менеджера</Label>
                        <Input id="manager_email" name="manager_email" type="email" bind:value={managerEmail} maxlength={255} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="manager_phone">Телефон менеджера</Label>
                        <Input id="manager_phone" name="manager_phone" bind:value={managerPhone} maxlength={255} />
                    </div>
                    <div class="grid gap-2">
                        <Label for="project_id">Проект</Label>
                        <select
                            id="project_id"
                            bind:value={projectId}
                            class="rounded-md border border-line bg-background px-3 py-2 text-sm"
                            disabled={!canEdit}
                            data-test="project-select"
                        >
                            <option value="">Без проекта</option>
                            {#each projects as project (project.id)}
                                <option value={project.id}>{project.name}</option>
                            {/each}
                        </select>
                    </div>
                </div>

                <div class="mt-8">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h2 class="text-base font-medium">Позиции</h2>
                        {#if canEdit}
                            <div class="flex flex-wrap gap-2">
                                <Button type="button" size="sm" variant="outline" onclick={openPicker} data-test="add-from-catalog">
                                    Из справочника
                                </Button>
                                <Button type="button" size="sm" variant="outline" onclick={showCatalogDiff} data-test="refresh-from-catalog">
                                    Обновить из справочника
                                </Button>
                                <Button type="button" size="sm" variant="outline" onclick={addItem} data-test="add-item">Добавить позицию</Button>
                            </div>
                        {/if}
                    </div>

                    {#if pickerOpen}
                        <div class="mb-3 rounded-lg border border-line p-3" data-test="catalog-picker">
                            <div class="flex gap-2">
                                <Input bind:value={pickerQuery} placeholder="Поиск по номенклатуре" oninput={() => void runCatalogSearch()} class="flex-1" />
                                <Button type="button" size="sm" variant="ghost" onclick={() => (pickerOpen = false)}>Закрыть</Button>
                            </div>
                            <div class="mt-2 divide-y divide-border">
                                {#each pickerResults as option (option.id)}
                                    <button
                                        type="button"
                                        class="flex w-full items-center justify-between gap-2 px-2 py-2 text-left text-sm hover:bg-muted"
                                        onclick={() => pickItem(option)}
                                    >
                                        <span>
                                            {option.name}
                                            {#if option.category}<span class="text-muted-foreground">· {option.category}</span>{/if}
                                        </span>
                                        <span class="tabular-nums text-muted-foreground">{formatMoney(option.price)}</span>
                                    </button>
                                {:else}
                                    <p class="px-2 py-3 text-sm text-muted-foreground">Ничего не найдено</p>
                                {/each}
                            </div>
                        </div>
                    {/if}

                    {#if diffEntries !== null}
                        <div class="mb-3 rounded-lg border border-line p-3" data-test="catalog-diff">
                            {#if diffEntries.filter((entry) => entry.changed).length === 0}
                                <p class="text-sm text-muted-foreground">Изменений в справочнике нет.</p>
                            {:else}
                                <p class="mb-2 text-sm">Строки черновика отличаются от справочника:</p>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                        <tr class="border-b border-line text-left text-muted-foreground">
                                            <th class="px-2 py-1 font-medium">Поле</th>
                                            <th class="px-2 py-1 font-medium">В черновике</th>
                                            <th class="px-2 py-1 font-medium">В справочнике</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        {#each diffEntries.filter((entry) => entry.changed) as entry (entry.position)}
                                            {#if entry.name.current !== entry.name.catalog}
                                                <tr class="border-b border-line last:border-0">
                                                    <td class="px-2 py-1">Название (стр. {entry.position})</td>
                                                    <td class="px-2 py-1">{entry.name.current}</td>
                                                    <td class="px-2 py-1">{entry.name.catalog}</td>
                                                </tr>
                                            {/if}
                                            {#if entry.unit.current !== entry.unit.catalog}
                                                <tr class="border-b border-line last:border-0">
                                                    <td class="px-2 py-1">Единица (стр. {entry.position})</td>
                                                    <td class="px-2 py-1">{entry.unit.current ?? '—'}</td>
                                                    <td class="px-2 py-1">{entry.unit.catalog ?? '—'}</td>
                                                </tr>
                                            {/if}
                                            {#if entry.unit_price.current !== entry.unit_price.catalog}
                                                <tr class="border-b border-line last:border-0">
                                                    <td class="px-2 py-1">Цена (стр. {entry.position})</td>
                                                    <td class="px-2 py-1">{formatMoney(entry.unit_price.current)}</td>
                                                    <td class="px-2 py-1">{formatMoney(entry.unit_price.catalog)}</td>
                                                </tr>
                                            {/if}
                                        {/each}
                                        </tbody>
                                    </table>
                                </div>
                                <p class="mt-2 text-xs text-muted-foreground">
                                    Применение заменит название, единицу и цену связанных строк на значения справочника
                                    и пересчитает суммы. Количества и описания не меняются.
                                </p>
                            {/if}
                            <div class="mt-3 flex gap-2">
                                {#if diffEntries.some((entry) => entry.changed)}
                                    <Button type="button" size="sm" onclick={applyCatalogRefresh} data-test="apply-refresh">Применить обновление</Button>
                                {/if}
                                <Button type="button" size="sm" variant="ghost" onclick={() => (diffEntries = null)}>Закрыть</Button>
                            </div>
                        </div>
                    {/if}
                    <div class="overflow-x-auto rounded-lg border border-line">
                        <table class="w-full text-sm">
                            <thead>
                            <tr class="border-b border-line text-left text-muted-foreground">
                                <th class="px-3 py-2 font-medium">Название</th>
                                <th class="w-20 px-3 py-2 font-medium">Ед.</th>
                                <th class="w-24 px-3 py-2 font-medium">Мин</th>
                                <th class="w-24 px-3 py-2 font-medium">Макс</th>
                                <th class="w-28 px-3 py-2 font-medium">Цена</th>
                                <th class="px-3 py-2 text-right font-medium">Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            {#each items as item, index (index)}
                                <tr class="border-b border-line last:border-0">
                                    <td class="px-3 py-2">
                                        <Input bind:value={item.name} placeholder="Название работы" data-test="item-name" />
                                        {#if item.catalog_item_id}
                                            <span class="mt-1 inline-block rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">из справочника</span>
                                        {/if}
                                    </td>
                                    <td class="px-3 py-2"><Input bind:value={item.unit} /></td>
                                    <td class="px-3 py-2"><Input bind:value={item.quantity_min} inputmode="decimal" /></td>
                                    <td class="px-3 py-2"><Input bind:value={item.quantity_max} inputmode="decimal" /></td>
                                    <td class="px-3 py-2"><Input bind:value={item.unit_price} inputmode="decimal" /></td>
                                    <td class="px-3 py-2 text-right">
                                        {#if canEdit}
                                            <div class="flex justify-end gap-1">
                                                <Button type="button" size="sm" variant="ghost" disabled={index === 0} onclick={() => moveItem(index, -1)}>↑</Button>
                                                <Button type="button" size="sm" variant="ghost" disabled={index === items.length - 1} onclick={() => moveItem(index, 1)}>↓</Button>
                                                <Button type="button" size="sm" variant="ghost" onclick={() => removeItem(index)}>×</Button>
                                            </div>
                                        {/if}
                                    </td>
                                </tr>
                            {/each}
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-2 text-sm text-muted-foreground" data-test="totals-preview">
                        Предварительный итог: {formatMoney(previewTotals.min)}
                        {#if previewTotals.min !== previewTotals.max} – {formatMoney(previewTotals.max)}{/if}
                        (окончательный считает сервер)
                    </div>
                </div>

                <div class="mt-8">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-base font-medium">Блоки</h2>
                        {#if canEdit}
                            <div class="flex flex-wrap gap-2">
                                {#each Object.entries(sectionLabels) as [type, label] (type)}
                                    <Button type="button" size="sm" variant="outline" onclick={() => addSection(type as SectionType)}>+ {label}</Button>
                                {/each}
                            </div>
                        {/if}
                    </div>
                    <div class="space-y-4">
                        {#each sections as section, index (index)}
                            <div class="rounded-lg border border-line p-4" data-test="section-block">
                                <div class="mb-3 flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <input type="checkbox" bind:checked={section.enabled} aria-label="Показывать блок" />
                                        <span class="text-sm font-medium">{sectionLabels[section.type]}</span>
                                    </div>
                                    {#if canEdit}
                                        <Button type="button" size="sm" variant="ghost" onclick={() => removeSection(index)}>Удалить блок</Button>
                                    {/if}
                                </div>

                                {#if section.type === 'stages'}
                                    <div class="space-y-2">
                                        {#each section.payload.stages as stage, stageIndex (stageIndex)}
                                            <div class="flex gap-2">
                                                <Input bind:value={stage.title} placeholder="Этап" class="flex-1" />
                                                <Input bind:value={stage.duration} placeholder="Срок" class="w-40" />
                                                {#if canEdit}
                                                    <Button type="button" size="sm" variant="ghost"
                                                        onclick={() => (section.payload.stages = (section.payload.stages ?? []).filter((_, i) => i !== stageIndex))}>
                                                        ×
                                                    </Button>
                                                {/if}
                                            </div>
                                        {/each}
                                        {#if canEdit}
                                            <Button type="button" size="sm" variant="outline"
                                                onclick={() => (section.payload.stages = [...(section.payload.stages ?? []), { title: '', duration: '' }])}>
                                                Добавить этап
                                            </Button>
                                        {/if}
                                    </div>
                                {:else if section.type === 'price_badge'}
                                    <Input bind:value={section.payload.label} placeholder="Текст примечания" disabled={!canEdit} />
                                {:else if section.type === 'custom'}
                                    <div class="space-y-3">
                                        {#each section.blocks ?? [] as block, blockIndex (blockIndex)}
                                            <div class="rounded border border-line p-3">
                                                <div class="mb-2 flex items-center gap-2">
                                                    <select
                                                        bind:value={block.type}
                                                        class="rounded-md border border-line bg-background px-2 py-1.5 text-sm"
                                                        disabled={!canEdit}
                                                    >
                                                        <option value="paragraph">Абзац</option>
                                                        <option value="header">Заголовок</option>
                                                        <option value="list">Список</option>
                                                        <option value="table">Таблица</option>
                                                    </select>
                                                    {#if canEdit}
                                                        <Button type="button" size="sm" variant="ghost"
                                                            onclick={() => (section.blocks = (section.blocks ?? []).filter((_: unknown, i: number) => i !== blockIndex))}>
                                                            ×
                                                        </Button>
                                                    {/if}
                                                </div>
                                                {#if block.type === 'paragraph'}
                                                    <textarea bind:value={block.text} rows="3" placeholder="Текст абзаца" class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm"></textarea>
                                                {:else if block.type === 'header'}
                                                    <div class="flex gap-2">
                                                        <Input bind:value={block.text} placeholder="Заголовок" class="flex-1" disabled={!canEdit} />
                                                        <select bind:value={block.level} class="rounded-md border border-line bg-background px-2 py-2 text-sm" disabled={!canEdit}>
                                                            <option value={1}>H1</option>
                                                            <option value={2}>H2</option>
                                                            <option value={3}>H3</option>
                                                            <option value={4}>H4</option>
                                                        </select>
                                                    </div>
                                                {:else if block.type === 'list'}
                                                    <div class="flex gap-2">
                                                        <select bind:value={block.style} class="rounded-md border border-line bg-background px-2 py-2 text-sm" disabled={!canEdit}>
                                                            <option value="unordered">Маркированный</option>
                                                            <option value="ordered">Нумерованный</option>
                                                        </select>
                                                    </div>
                                                    <textarea bind:value={block.items} rows="4" placeholder="Пункты списка — каждый с новой строки" class="mt-2 w-full rounded-md border border-line bg-background px-3 py-2 text-sm"></textarea>
                                                {:else if block.type === 'table'}
                                                    <label class="flex items-center gap-2 text-sm">
                                                        <input type="checkbox" bind:checked={block.withHeadings} disabled={!canEdit} /> Первая строка — заголовки
                                                    </label>
                                                    <textarea bind:value={block.content} rows="4" placeholder="Строки таблицы; ячейки через Tab" class="mt-2 w-full rounded-md border border-line bg-background px-3 py-2 text-sm"></textarea>
                                                {/if}
                                            </div>
                                        {/each}
                                        {#if canEdit}
                                            <Button type="button" size="sm" variant="outline"
                                                onclick={() => (section.blocks = [...(section.blocks ?? []), emptyBlock('paragraph')])}>
                                                Добавить блок
                                            </Button>
                                        {/if}
                                    </div>
                                {:else if section.type === 'table'}
                                    <div class="grid gap-3 md:grid-cols-2">
                                        <div class="grid gap-1">
                                            <Label>Колонки (key:название, каждая с новой строки)</Label>
                                            <textarea
                                                value={tableColumnsText(section)}
                                                oninput={(e) => (section.payload.columns = parseTableColumns((e.target as HTMLTextAreaElement).value))}
                                                rows="4"
                                                class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm"
                                                disabled={!canEdit}
                                            ></textarea>
                                        </div>
                                        <div class="grid gap-1">
                                            <Label>Строки (ячейки через Tab)</Label>
                                            <textarea
                                                value={tableRowsText(section)}
                                                oninput={(e) => (section.payload.rows = ((e.target as HTMLTextAreaElement).value
                                                    .split('\n').filter((line) => line.trim() !== '').map((line) => line.split('\t'))))}
                                                rows="4"
                                                class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm"
                                                disabled={!canEdit}
                                            ></textarea>
                                        </div>
                                    </div>
                                    <div class="mt-3 flex items-center gap-4">
                                        <Label class="flex items-center gap-2">
                                            Ценовая колонка
                                            <select
                                                value={(section.payload.price_column as string | null) ?? ''}
                                                onchange={(e) => (section.payload.price_column = (e.target as HTMLSelectElement).value || null)}
                                                class="rounded-md border border-line bg-background px-2 py-1.5 text-sm"
                                                disabled={!canEdit}
                                            >
                                                <option value="">—</option>
                                                {#each (section.payload.columns as { key: string }[]) ?? [] as column (column.key)}
                                                    <option value={column.key}>{column.key}</option>
                                                {/each}
                                            </select>
                                        </Label>
                                        <Label class="flex items-center gap-2">
                                            <input type="checkbox" bind:checked={section.payload.include_in_total} disabled={!canEdit} />
                                            Включать в итог
                                        </Label>
                                    </div>
                                {:else if section.type === 'license'}
                                    <div class="space-y-2">
                                        {#each section.payload.items as license, licenseIndex (licenseIndex)}
                                            <div class="flex gap-2">
                                                <Input bind:value={license.name} placeholder="Услуга" class="flex-1" disabled={!canEdit} />
                                                <Input bind:value={license.period} placeholder="Период" class="w-32" disabled={!canEdit} />
                                                <Input bind:value={license.price} placeholder="0.00" class="w-28" inputmode="decimal" disabled={!canEdit} />
                                                {#if canEdit}
                                                    <Button type="button" size="sm" variant="ghost"
                                                        onclick={() => (section.payload.items = (section.payload.items ?? []).filter((_, i) => i !== licenseIndex))}>
                                                        ×
                                                    </Button>
                                                {/if}
                                            </div>
                                        {/each}
                                        {#if canEdit}
                                            <Button type="button" size="sm" variant="outline"
                                                onclick={() => (section.payload.items = [...(section.payload.items ?? []), { name: '', period: 'в месяц', price: '0.00' }])}>
                                                Добавить услугу
                                            </Button>
                                        {/if}
                                    </div>
                                {/if}
                            </div>
                        {/each}
                    </div>
                </div>

                <div class="mt-8 grid gap-2">
                    <Label for="note">Примечание</Label>
                    <textarea id="note" name="note" bind:value={note} rows="3" maxlength="10000"
                        class="rounded-md border border-line bg-background px-3 py-2 text-sm"></textarea>
                </div>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    {#if canEdit}
                        <Button type="submit" disabled={processing} data-test="save-draft">Сохранить черновик</Button>
                    {/if}
                    {#if canIssue}
                        <Button type="button" variant="outline" disabled={processing} onclick={saveAndIssue} data-test="issue-proposal">
                            Сохранить и выпустить КП
                        </Button>
                    {/if}
                    {#if canEdit}
                        <Button type="button" variant="ghost" disabled={processing}
                            onclick={() => { if (confirm('Удалить черновик? Действие необратимо.')) router.delete(destroy.url(proposal)); }}>
                            Удалить черновик
                        </Button>
                    {/if}
                </div>
            {/snippet}
        </Form>
    {/if}
</div>
