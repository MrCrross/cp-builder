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
    import { onMount, untrack } from 'svelte';
    import { Form, page, router } from '@inertiajs/svelte';
    import { toast } from 'svelte-sonner';
    import { store } from '@/routes/proposals';
    import { destroy, issue, archive, newDraft, restore, rotateLink, update } from '@/routes/proposals';
    import { catalogDiff, refreshCatalog } from '@/routes/proposals';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import InputError from '@/shared/ui/InputError.svelte';
    import { Badge } from '@/shared/ui/badge';
    import { Button } from '@/shared/ui/button';
    import { confirmDialog } from '@/shared/ui/confirm-dialog';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/shared/ui/dropdown-menu';
    import {
        formatMoney,
        type CatalogDiffEntry,
        type ProposalEditPayload,
        type ProposalItemInput,
        type ProposalSectionInput,
        type ProposalTemplateOption,
        type ProjectOption,
    } from '@/shared/api';
    import {
        blocksToHtml,
        extractSectionErrors,
        lineAmount,
        sectionHasContent,
        sectionHints,
        sectionLabels,
        type SectionRow,
        type SectionType,
    } from './blocks';
    import SectionCard from './SectionCard.svelte';
    import VersionsPanel from './VersionsPanel.svelte';
    import StagesBlock from './StagesBlock.svelte';
    import RichTextBlock from './RichTextBlock.svelte';
    import TableBlock from './TableBlock.svelte';
    import LicenseBlock from './LicenseBlock.svelte';
    import ItemsBlock from './ItemsBlock.svelte';
    import Plus from '@lucide/svelte/icons/plus';

    const proposal = $derived(page.props.proposal as ProposalEditPayload | null);
    const templates = $derived((page.props.templates as ProposalTemplateOption[]) ?? []);
    const projects = $derived((page.props.projects as ProjectOption[]) ?? []);
    const permissions = $derived((page.props.auth?.permissions ?? []) as string[]);

    const canEdit = $derived(permissions.includes('proposals.update'));
    const canIssue = $derived(permissions.includes('proposals.issue'));

    // -- Создание ------------------------------------------------------------
    let templateId: number | '' = $state('');

    // -- Редактор черновика ----------------------------------------------------

    /** Payload с сервера → редактируемая секция (custom: legacy-блоки → HTML). */
    function parseSection(input: ProposalSectionInput): SectionRow {
        const raw = (input.payload ?? {}) as Record<string, unknown>;
        const enabled = input.enabled !== false;
        const title = (raw.title as string | null | undefined) ?? null;

        switch (input.type) {
            case 'items': {
                const rows = Array.isArray(raw.items) ? raw.items : [];
                return {
                    type: 'items',
                    enabled,
                    payload: {
                        title,
                        items: rows.map((row) => {
                            const item = row as Record<string, unknown> | null;
                            return {
                                catalog_item_id: typeof item?.catalog_item_id === 'number' ? item.catalog_item_id : null,
                                name: String(item?.name ?? ''),
                                description: typeof item?.description === 'string' ? item.description : null,
                                unit: typeof item?.unit === 'string' ? item.unit : null,
                                quantity_min: String(item?.quantity_min ?? '1'),
                                quantity_max: String(item?.quantity_max ?? '1'),
                                unit_price: String(item?.unit_price ?? '0.00'),
                            };
                        }),
                    },
                };
            }
            case 'stages': {
                const stages = Array.isArray(raw.stages) ? raw.stages : [];
                return {
                    type: 'stages',
                    enabled,
                    payload: {
                        title,
                        stages: stages.map((stage) => {
                            const item = stage as Record<string, unknown> | null;
                            return { title: String(item?.title ?? ''), duration: String(item?.duration ?? '') };
                        }),
                    },
                };
            }
            case 'custom':
                return {
                    type: 'custom',
                    enabled,
                    payload: {
                        title,
                        html: typeof raw.html === 'string' ? raw.html : blocksToHtml(raw.blocks),
                    },
                };
            case 'table': {
                const columns = Array.isArray(raw.columns) ? raw.columns : [];
                const rows = Array.isArray(raw.rows) ? raw.rows : [];
                return {
                    type: 'table',
                    enabled,
                    payload: {
                        title,
                        columns: columns.map((column, i) => {
                            const item = column as Record<string, unknown> | null;
                            const type = item?.type;
                            return {
                                key: String(item?.key ?? `col_${i + 1}`),
                                label: String(item?.label ?? ''),
                                type: type === 'number' || type === 'money' ? type : 'text',
                            };
                        }),
                        rows: rows.map((row) => (Array.isArray(row) ? row.map((cell) => String(cell ?? '')) : [])),
                        price_column: typeof raw.price_column === 'string' ? raw.price_column : null,
                        include_in_total: Boolean(raw.include_in_total),
                    },
                };
            }
            case 'license': {
                const items = Array.isArray(raw.items) ? raw.items : [];
                return {
                    type: 'license',
                    enabled,
                    payload: {
                        title,
                        items: items.map((license) => {
                            const item = license as Record<string, unknown> | null;
                            return {
                                name: String(item?.name ?? ''),
                                period: String(item?.period ?? ''),
                                price: String(item?.price ?? ''),
                            };
                        }),
                    },
                };
            }
            case 'price_badge':
            default:
                return { type: 'price_badge', enabled, payload: { label: String(raw.label ?? '') } };
        }
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
    let sections = $state<SectionRow[]>([]);
    /** Свёрнутость карточек по индексу секции. */
    let openMap = $state<boolean[]>([]);
    /** Базовый снимок формы для отслеживания несохранённых изменений. */
    let baseline = '';

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
        let parsed = draft.sections.map(parseSection);
        // Миграция legacy-черновиков: позиции лежали топ-уровнем — переносим
        // их в блок «Позиции» первым, дальше сохранится уже секцией.
        if (parsed.every((section) => section.type !== 'items') && draft.items.length > 0) {
            parsed = [
                { type: 'items', enabled: true, payload: { title: 'Позиции', items: draft.items.map((i) => ({ ...i })) } },
                ...parsed,
            ];
        }
        sections = parsed;
        openMap = parsed.map(() => true);
        // snapshot() читает редактируемое состояние — читаем в untrack, иначе
        // эффект зависит от полей формы и зацикливается (read-after-write).
        baseline = untrack(() => snapshot());
    });

    $effect(() => {
        projectId = proposal?.project_id ?? '';
    });

    // -- Клиентский предпросмотр сумм (не доверенные значения, §7.2) ----------
    const previewTotals = $derived.by(() => {
        let min = 0;
        let max = 0;
        for (const section of sections) {
            if (section.type !== 'items' || !section.enabled) continue;
            for (const item of section.payload.items) {
                min += lineAmount(item.quantity_min, item.unit_price);
                max += lineAmount(item.quantity_max, item.unit_price);
            }
        }
        const fmt = (cents: number): string => (cents / 100).toFixed(2);
        return { min: fmt(min), max: fmt(max) };
    });

    // -- Picker номенклатуры -----------------------------------------------------

    let diffEntries = $state<CatalogDiffEntry[] | null>(null);

    async function showCatalogDiff() {
        if (!proposal) return;
        const response = await fetch(`${catalogDiff.url(proposal)}?branch_id=${proposal.draft?.branch_id ?? ''}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = (await response.json()) as { diff: CatalogDiffEntry[] };
        diffEntries = data.diff;
    }

    function applyCatalogRefresh() {
        if (!proposal) return;
        router.post(refreshCatalog.url(proposal), { branch_id: proposal.draft?.branch_id }, { preserveScroll: true });
        diffEntries = null;
    }

    // -- Секции ----------------------------------------------------------------
    const sectionTypeOrder: SectionType[] = ['items', 'stages', 'custom', 'table', 'license', 'price_badge'];

    function createSection(type: SectionType): SectionRow {
        switch (type) {
            case 'items':
                return {
                    type,
                    enabled: true,
                    payload: {
                        title: 'Позиции',
                        items: [{ catalog_item_id: null, name: '', description: null, unit: 'ч', quantity_min: '1', quantity_max: '1', unit_price: '0.00' }],
                    },
                };
            case 'stages':
                return { type, enabled: true, payload: { title: 'Этапы работ', stages: [{ title: '', duration: '' }] } };
            case 'custom':
                return { type, enabled: true, payload: { title: null, html: '' } };
            case 'table':
                return {
                    type,
                    enabled: true,
                    payload: {
                        title: null,
                        columns: [{ key: 'col_1', label: 'Наименование', type: 'text' }],
                        rows: [['']],
                        price_column: null,
                        include_in_total: false,
                    },
                };
            case 'license':
                return { type, enabled: true, payload: { title: 'Лицензии и расходы', items: [{ name: '', period: 'в месяц', price: '0.00' }] } };
            case 'price_badge':
                return { type, enabled: true, payload: { label: 'Стоимость по запросу' } };
        }
    }

    function addSection(type: SectionType) {
        sections = [...sections, createSection(type)];
        openMap = [...openMap, true];
    }

    async function removeSection(index: number) {
        const section = sections[index];
        if (sectionHasContent(section)) {
            const ok = await confirmDialog({
                title: `Удалить блок «${sectionLabels[section.type]}»?`,
                description: 'Введённые данные будут потеряны.',
                variant: 'danger',
                confirmText: 'Удалить',
            });
            if (!ok) return;
        }
        sections = sections.filter((_, i) => i !== index);
        openMap = openMap.filter((_, i) => i !== index);
    }

    function moveSection(index: number, delta: number) {
        const target = index + delta;
        if (target < 0 || target >= sections.length) return;
        const next = [...sections];
        [next[index], next[target]] = [next[target], next[index]];
        sections = next;
        const opens = [...openMap];
        [opens[index], opens[target]] = [opens[target], opens[index]];
        openMap = opens;
    }

    // -- Порядок блоков перетаскиванием ------------------------------------------
    let dragIndex: number | null = $state(null);
    let overIndex: number | null = $state(null);

    function dropSection() {
        if (dragIndex === null || overIndex === null || dragIndex === overIndex) {
            dragIndex = null;
            overIndex = null;
            return;
        }
        const next = [...sections];
        const [moved] = next.splice(dragIndex, 1);
        next.splice(overIndex, 0, moved);
        sections = next;
        const opens = [...openMap];
        const [movedOpen] = opens.splice(dragIndex, 1);
        opens.splice(overIndex, 0, movedOpen);
        openMap = opens;
        dragIndex = null;
        overIndex = null;
    }

    // -- Несохранённые изменения -------------------------------------------------
    onMount(() => {
        const offBefore = router.on('before', (event) => {
            if (!dirty || !proposal) return;
            // Сохранение/выпуск — не уход со страницы, подтверждение не нужно
            if (event.detail.visit.url.toString().includes(`/proposals/${proposal.id}`)) return;
            event.preventDefault();
            const visit = event.detail.visit;
            const pending: PendingVisit = {
                url: visit.url.toString(),
                method: visit.method,
                data: visit.data as Record<string, import('@inertiajs/core').FormDataConvertible>,
            };
            void confirmDialog({
                title: 'Покинуть страницу?',
                description: 'Есть несохранённые изменения. Покинуть страницу без сохранения?',
                confirmText: 'Покинуть',
            }).then((ok) => {
                if (ok) {
                    router.visit(pending.url, { method: pending.method, data: pending.data });
                }
            });
        });

        return () => {
            offBefore();
        };
    });

    // Предупреждение при закрытии/перезагрузке вкладки с несохранёнными данными
    $effect(() => {
        if (!dirty) return;
        const handler = (event: BeforeUnloadEvent) => event.preventDefault();
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    });

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
            branch_id: proposal?.draft?.branch_id ?? null,
            lock_version: proposal?.draft?.lock_version ?? 1,
            sections: sections.map((section) => ({
                type: section.type,
                enabled: section.enabled,
                payload: toWirePayload(section),
            })),
            // Произвольный вложенный JSON payload — транспорт допускает его,
        } as Record<string, import('@inertiajs/core').FormDataConvertible>;
    }

    function toWirePayload(section: SectionRow): Record<string, unknown> {
        switch (section.type) {
            case 'items':
                return {
                    title: section.payload.title ?? null,
                    // Пустой placeholder-ряд (только что добавленный блок) не шлём —
                    // серверная валидация требует имя, пустые ряды — мусор.
                    items: section.payload.items
                        .filter((item) => item.name.trim() !== '')
                        .map((item) => ({
                        catalog_item_id: item.catalog_item_id,
                        name: item.name,
                        description: item.description,
                        unit: item.unit,
                        quantity_min: item.quantity_min,
                        quantity_max: item.quantity_max,
                        unit_price: item.unit_price,
                    })),
                };
            case 'custom':
                return { title: section.payload.title ?? null, html: section.payload.html };
            case 'table':
                return {
                    title: section.payload.title ?? null,
                    columns: section.payload.columns.map((column) => ({ key: column.key, label: column.label, type: column.type })),
                    rows: section.payload.rows,
                    price_column: section.payload.price_column,
                    include_in_total: section.payload.include_in_total,
                };
            default:
                return { ...section.payload };
        }
    }

    function snapshot(): string {
        return JSON.stringify({ ...buildPayload(), lock_version: undefined });
    }

    const dirty = $derived(snapshot() !== baseline);

    async function saveAndIssue() {
        const ok = await confirmDialog({
            title: 'Выпустить КП?',
            description: 'Создастся публичный документ. Публичная ссылка доступна в панели «Версии и ветки».',
            confirmText: 'Сохранить и выпустить',
        });
        if (!ok || !proposal) return;

        router.put(update.url(proposal), buildPayload(), {
            preserveScroll: true,
            onError: () => onConflict(),
            onSuccess: () => router.post(issue.url(proposal), { branch_id: proposal.draft?.branch_id }, { preserveScroll: true }),
        });
    }

    async function deleteDraft() {
        if (!proposal) return;
        const ok = await confirmDialog({
            title: 'Удалить черновик?',
            description: 'Действие необратимо.',
            variant: 'danger',
            confirmText: 'Удалить',
        });
        if (!ok) return;
        router.delete(destroy.url(proposal));
    }

    async function createNewVersion() {
        if (!proposal) return;
        const ok = await confirmDialog({
            title: 'Создать новую версию?',
            description: 'Новый черновик будет создан из копии текущей версии.',
            confirmText: 'Создать',
        });
        if (!ok) return;
        const branchId = proposal.branches.find((branch) => branch.name === proposal.active_branch)?.id;
        router.post(newDraft.url(proposal), { branch_id: branchId }, { preserveScroll: true });
    }

    async function rotatePublicLink() {
        if (!proposal) return;
        const ok = await confirmDialog({
            title: 'Заменить публичную ссылку?',
            description: 'Старая ссылка перестанет работать.',
            confirmText: 'Заменить',
        });
        if (!ok) return;
        router.post(rotateLink.url(proposal), {});
    }

    async function archiveProposal() {
        if (!proposal) return;
        const ok = await confirmDialog({
            title: 'Архивировать КП?',
            description: 'Публичная ссылка будет закрыта.',
            variant: 'danger',
            confirmText: 'Архивировать',
        });
        if (!ok) return;
        router.post(archive.url(proposal), {});
    }

    interface PendingVisit {
        url: string;
        method: import('@inertiajs/core').Method;
        data: Record<string, import('@inertiajs/core').FormDataConvertible>;
    }

    function onConflict() {
        alert('Черновик изменён другим пользователем или уже выпущен. Сейчас страница обновится — скопируйте введённые данные.');
        router.reload();
    }
</script>

<AppHead title={proposal ? `КП ${proposal.number ?? ''}` : 'Новое КП'} />

<h1 class="sr-only">Редактор коммерческого предложения</h1>

<div class="flex flex-col space-y-6 p-4">
    <div class="flex items-start justify-between gap-4">
        <Heading
            variant="small"
            title={proposal ? `КП ${proposal.number ?? 'без номера'}` : 'Новое коммерческое предложение'}
            description={proposal?.current_version ? `Текущая версия v${proposal.current_version.version}.` : 'Черновик не публикуется, пока не выпущен.'}
        />
        {#if proposal}
            <div class="flex items-center gap-2">
                <Badge variant={proposal.status === 'issued' ? 'default' : 'secondary'}>
                    {proposal.status === 'draft' ? 'Черновик' : proposal.status === 'issued' ? 'Выпущено' : 'Архив'}
                </Badge>
                <Button variant="outline" href={proposal.preview_url ?? undefined} target="_blank">Просмотр</Button>
            </div>
        {/if}
    </div>

    {#if proposal}
        <VersionsPanel {proposal} {canEdit} />
    {/if}

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
            {#if proposal.current_version}
                <p class="text-sm text-muted-foreground">
                    Текущая версия v{proposal.current_version.version}. Публичная ссылка активна
                    и доступна для копирования в панели «Версии и ветки».
                </p>
                <div class="flex gap-2">
                    {#if canEdit && proposal.status === 'issued'}
                        <Button onclick={createNewVersion}>
                            Новая версия
                        </Button>
                    {/if}
                    {#if proposal.status === 'issued'}
                        <Button variant="outline" onclick={rotatePublicLink}>
                            Заменить ссылку
                        </Button>
                        <Button variant="ghost" onclick={archiveProposal}>
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
            onSuccess={() => toast.success('Черновик сохранён')}
            onError={() => toast.error('Не удалось сохранить — проверьте отмеченные поля')}
            transform={(data) => ({ ...data, ...buildPayload() })}
        >
            {#snippet children({ errors, processing })}
                {#if Object.keys(errors).length > 0}
                    <div class="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive" role="alert" data-test="form-error-banner">
                        Не удалось сохранить черновик. Проверьте отмеченные поля.
                    </div>
                {/if}

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="grid gap-2 md:col-span-2">
                        <Label for="title">Название документа</Label>
                        <Input id="title" name="title" bind:value={title} required maxlength={500} aria-invalid={Boolean(errors.title)} />
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
                        <h2 class="text-base font-medium">Блоки</h2>
                        {#if canEdit}
                            <div class="flex flex-wrap items-center gap-2">
                                <Button type="button" size="sm" variant="outline" onclick={showCatalogDiff} data-test="refresh-from-catalog">
                                    Обновить из справочника
                                </Button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        {#snippet children(trigger)}
                                            <Button type="button" size="sm" variant="outline" {...trigger} data-test="add-block">
                                                <Plus class="size-4" />
                                                Добавить блок
                                            </Button>
                                        {/snippet}
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" class="w-64">
                                        {#each sectionTypeOrder as type (type)}
                                            <DropdownMenuItem onclick={() => addSection(type)}>
                                                <div class="grid gap-0.5 py-0.5">
                                                    <span>{sectionLabels[type]}</span>
                                                    <span class="text-xs text-muted-foreground">{sectionHints[type]}</span>
                                                </div>
                                            </DropdownMenuItem>
                                        {/each}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        {/if}
                    </div>

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

                    {#if sections.length === 0}
                        <div class="rounded-lg border border-dashed border-line px-4 py-8 text-center text-sm text-muted-foreground" data-test="sections-empty">
                            Блоков пока нет. Нажмите «Добавить блок», чтобы вставить позиции, этапы, текст или таблицу.
                        </div>
                    {:else}
                        <div class="space-y-4">
                            {#each sections as section, index (index)}
                                <SectionCard
                                    {index}
                                    total={sections.length}
                                    label={sectionLabels[section.type]}
                                    hint={sectionHints[section.type]}
                                    bind:enabled={section.enabled}
                                    bind:open={openMap[index]}
                                    {canEdit}
                                    hasContent={sectionHasContent(section)}
                                    dragging={dragIndex === index}
                                    dragOver={dragIndex !== null && overIndex === index && dragIndex !== index}
                                    ondelete={() => removeSection(index)}
                                    onmove={(delta) => moveSection(index, delta)}
                                    ondragstart={() => (dragIndex = index)}
                                    ondragend={() => {
                                        dragIndex = null;
                                        overIndex = null;
                                    }}
                                    ondragover={() => (overIndex = index)}
                                    ondrop={dropSection}
                                >
                                    {#snippet body()}
                                        {#if section.type === 'items'}
                                            <ItemsBlock bind:items={section.payload.items} {canEdit} errors={extractSectionErrors(errors, index)} />
                                        {:else if section.type === 'stages'}
                                            <StagesBlock bind:stages={section.payload.stages} {canEdit} errors={extractSectionErrors(errors, index)} />
                                        {:else if section.type === 'custom'}
                                            <RichTextBlock bind:html={section.payload.html} {canEdit} />
                                        {:else if section.type === 'table'}
                                            <TableBlock bind:payload={section.payload} {canEdit} errors={extractSectionErrors(errors, index)} />
                                        {:else if section.type === 'license'}
                                            <LicenseBlock bind:items={section.payload.items} {canEdit} errors={extractSectionErrors(errors, index)} />
                                        {:else}
                                            <div class="grid gap-2">
                                                <Label for="price-badge-{index}">Текст примечания</Label>
                                                <Input
                                                    id="price-badge-{index}"
                                                    bind:value={section.payload.label}
                                                    placeholder="Например: Стоимость по запросу"
                                                    disabled={!canEdit}
                                                    aria-invalid={Boolean(extractSectionErrors(errors, index)['label'])}
                                                />
                                                <InputError message={extractSectionErrors(errors, index)['label']} />
                                            </div>
                                        {/if}
                                    {/snippet}
                                </SectionCard>
                            {/each}
                        </div>
                    {/if}

                    {#if sections.some((section) => section.type === 'items')}
                        <div class="mt-3 text-sm text-muted-foreground" data-test="totals-preview">
                            Предварительный итог: {formatMoney(previewTotals.min)}
                            {#if previewTotals.min !== previewTotals.max} – {formatMoney(previewTotals.max)}{/if}
                            (окончательный считает сервер)
                        </div>
                    {/if}
                </div>

                <div class="mt-8 grid gap-2">
                    <Label for="note">Примечание</Label>
                    <textarea id="note" name="note" bind:value={note} rows="3" maxlength="10000"
                        class="rounded-md border border-line bg-background px-3 py-2 text-sm"></textarea>
                </div>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    {#if canEdit}
                        <Button type="submit" disabled={processing} data-test="save-draft">
                            {processing ? 'Сохранение…' : 'Сохранить черновик'}
                        </Button>
                    {/if}
                    {#if canIssue}
                        <Button type="button" variant="outline" disabled={processing} onclick={saveAndIssue} data-test="issue-proposal">
                            Сохранить и выпустить КП
                        </Button>
                    {/if}
                    {#if dirty}
                        <span class="text-xs text-muted-foreground" data-test="dirty-hint">Есть несохранённые изменения</span>
                    {/if}
                    {#if canEdit}
                        <Button type="button" variant="ghost" disabled={processing}
                            onclick={deleteDraft}>
                            Удалить черновик
                        </Button>
                    {/if}
                </div>
            {/snippet}
        </Form>
    {/if}
</div>
