<script module lang="ts">
    import { index } from '@/routes/imports';

    export const layout = {
        breadcrumbs: [{ title: 'Импорты', href: index() }],
    };
</script>

<script lang="ts">
    import { onDestroy, onMount } from 'svelte';
    import { page, router } from '@inertiajs/svelte';
    import { cancel as importsCancel, confirm as importsConfirm, errors as importsErrors, index as importsIndex } from '@/routes/imports';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import { Badge } from '@/shared/ui/badge';
    import { Button } from '@/shared/ui/button';
    import { Pagination } from '@/shared/ui/pagination';
    import { confirmDialog } from '@/shared/ui/confirm-dialog';
    import { formatMoney, type ImportJobDetail, type ImportRowActionValue, type ImportRowItem } from '@/shared/api';

    interface PaginatedRows {
        data: ImportRowItem[];
        links: { url: string | null; label: string; active: boolean }[];
    }

    const job = $derived((page.props.job as ImportJobDetail) ?? null);
    const rows = $derived((page.props.rows as PaginatedRows) ?? { data: [], links: [] });
    const filters = $derived(page.props.filters as { action?: ImportRowActionValue | null; errors?: string | null });

    const statusLabels: Record<string, string> = {
        uploaded: 'Загружен',
        validating: 'Проверка',
        awaiting_confirmation: 'Ожидает подтверждения',
        processing: 'Применение',
        completed: 'Завершён',
        completed_with_errors: 'Завершён с ошибками',
        failed: 'Ошибка',
        cancelled: 'Отменён',
    };

    const actionLabels: Record<ImportRowActionValue, string> = {
        add: 'Добавление',
        change: 'Изменение',
        skip: 'Без изменений',
        delete: 'Отключение',
    };

    const activeStatuses = ['uploaded', 'validating', 'processing'];
    const isActive = $derived(job ? activeStatuses.includes(job.status) : false);
    const awaitingConfirmation = $derived(job?.status === 'awaiting_confirmation');
    const cancellable = $derived(job ? ['uploaded', 'validating', 'awaiting_confirmation'].includes(job.status) : false);

    let pollTimer: ReturnType<typeof setInterval> | null = null;

    onMount(() => {
        pollTimer = setInterval(() => {
            if (isActive) router.reload();
        }, 3000);
    });

    onDestroy(() => {
        if (pollTimer) clearInterval(pollTimer);
    });

    async function applyImport() {
        if (!job) return;
        const ok = await confirmDialog({
            title: 'Применить импорт к каталогу?',
            description: `Добавит: ${job.rows_add}, изменит: ${job.rows_change}, отключит: ${job.rows_delete}. Это не изменит уже выпущенные КП.`,
            confirmText: 'Применить',
        });
        if (!ok) return;
        router.post(importsConfirm.url(job), {}, { preserveScroll: true });
    }

    async function cancelImport() {
        if (!job) return;
        const ok = await confirmDialog({
            title: 'Отменить импорт?',
            description: 'Каталог не будет изменён.',
            confirmText: 'Отменить импорт',
        });
        if (!ok) return;
        router.post(importsCancel.url(job), {}, { preserveScroll: true });
    }

    function filterUrl(action: ImportRowActionValue | null, errorsOnly: boolean): string {
        const params = new URLSearchParams();
        if (action) params.set('action', action);
        if (errorsOnly) params.set('errors', '1');
        const query = params.toString();
        return job ? `${importsIndex.url()}/${job.id}${query ? `?${query}` : ''}` : importsIndex.url();
    }
</script>

<AppHead title={`Импорт №${job?.id ?? ''}`} />

<h1 class="sr-only">Импорт</h1>

{#if job}
<div class="flex flex-col space-y-6 p-4">
    <Heading
        variant="small"
        title={`Импорт №${job.id}`}
        description={job.profile === 'commerceml' ? 'Профиль CommerceML 3.1 (1С)' : 'Профиль YML (Яндекс Маркет)'}
    />

    <div class="flex flex-wrap items-center gap-3">
        <Badge variant={job.status === 'failed' ? 'destructive' : job.status === 'completed' ? 'default' : 'outline'}>
            {statusLabels[job.status] ?? job.status}
        </Badge>
        {#if isActive}
            <span class="text-sm text-muted-foreground">Обработка идёт, страница обновляется автоматически…</span>
        {/if}
        {#if awaitingConfirmation}
            <Button onclick={applyImport} data-test="import-apply">Применить к каталогу</Button>
        {/if}
        {#if cancellable}
            <Button variant="outline" onclick={cancelImport}>Отменить</Button>
        {/if}
        {#if job.rows_error > 0}
            <Button variant="outline" href={importsErrors.url(job)}>Скачать отчёт об ошибках (CSV)</Button>
        {/if}
        <Button variant="ghost" href={importsIndex.url()}>К списку импортов</Button>
    </div>

    {#if job.error_message}
        <div class="max-w-4xl rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
            {job.error_message}
        </div>
    {/if}

    <div class="grid max-w-4xl grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-lg border border-line p-3">
            <div class="text-xs text-muted-foreground">Всего строк</div>
            <div class="text-lg font-medium tabular-nums">{job.rows_total}</div>
        </div>
        <div class="rounded-lg border border-line p-3">
            <div class="text-xs text-muted-foreground">Добавлено / обновлено</div>
            <div class="text-lg font-medium tabular-nums">{job.applied_created} / {job.applied_updated}</div>
        </div>
        <div class="rounded-lg border border-line p-3">
            <div class="text-xs text-muted-foreground">Отключено</div>
            <div class="text-lg font-medium tabular-nums">{job.applied_deactivated}</div>
        </div>
        <div class="rounded-lg border border-line p-3">
            <div class="text-xs text-muted-foreground">Обработано</div>
            <div class="text-lg font-medium tabular-nums">{job.rows_processed}</div>
        </div>
    </div>

    {#if job.files.length > 0}
        <div class="max-w-4xl overflow-x-auto rounded-lg border border-line">
            <table class="w-full text-sm">
                <thead>
                <tr class="border-b border-line text-left text-muted-foreground">
                    <th class="px-4 py-2 font-medium">Файл</th>
                    <th class="px-4 py-2 font-medium">Вид</th>
                    <th class="px-4 py-2 text-right font-medium">Размер</th>
                    <th class="px-4 py-2 text-right font-medium">Разобрано</th>
                    <th class="px-4 py-2 font-medium">Ошибка</th>
                </tr>
                </thead>
                <tbody>
                {#each job.files as file (file.id)}
                    <tr class="border-b border-line last:border-0">
                        <td class="px-4 py-2 font-medium">{file.original_name}</td>
                        <td class="px-4 py-2 text-muted-foreground">{file.detected_kind ?? '—'}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{(file.size_bytes / 1048576).toFixed(1)} МБ</td>
                        <td class="px-4 py-2 text-right tabular-nums">{file.parsed_count}</td>
                        <td class="px-4 py-2 text-destructive">{file.error ?? ''}</td>
                    </tr>
                {/each}
                </tbody>
            </table>
        </div>
    {/if}

    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm text-muted-foreground">Фильтр:</span>
        <Badge variant={!filters.action && !filters.errors ? 'default' : 'outline'}><a href={filterUrl(null, false)}>Все</a></Badge>
        {#each Object.keys(actionLabels) as action}
            <Badge variant={filters.action === action ? 'default' : 'outline'}>
                <a href={filterUrl(action as ImportRowActionValue, filters.errors === '1')}>{actionLabels[action as ImportRowActionValue]}</a>
            </Badge>
        {/each}
        <Badge variant={filters.errors === '1' ? 'destructive' : 'outline'}><a href={filterUrl(filters.action ?? null, filters.errors !== '1')}>С ошибками</a></Badge>
    </div>

    <div class="overflow-x-auto rounded-lg border border-line">
        <table class="w-full text-sm" data-test="import-rows-table">
            <thead>
            <tr class="border-b border-line text-left text-muted-foreground">
                <th class="px-4 py-2 font-medium">Внешний ID</th>
                <th class="px-4 py-2 font-medium">Название</th>
                <th class="px-4 py-2 font-medium">Картинка</th>
                <th class="px-4 py-2 font-medium">Свойства</th>
                <th class="px-4 py-2 font-medium">Ед.</th>
                <th class="px-4 py-2 text-right font-medium">Цена</th>
                <th class="px-4 py-2 font-medium">Действие</th>
                <th class="px-4 py-2 font-medium">Ошибки</th>
            </tr>
            </thead>
            <tbody>
            {#each rows.data as row (row.id)}
                <tr class="border-b border-line last:border-0">
                    <td class="max-w-48 truncate px-4 py-2 font-mono text-xs text-muted-foreground" title={row.external_id}>{row.external_id}</td>
                    <td class="px-4 py-2 font-medium">
                        {row.name ?? '—'}
                        {#if row.article}<span class="ml-1 text-xs text-muted-foreground">арт. {row.article}</span>{/if}
                        {#if row.source_url}
                            <a href={row.source_url} target="_blank" rel="noopener noreferrer" class="ml-1 text-xs text-primary underline" title={row.source_url}>источник</a>
                        {/if}
                    </td>
                    <td class="px-4 py-2">
                        {#if row.image && /^https?:\/\//.test(row.image)}
                            <img src={row.image} alt="" class="h-10 w-10 rounded border border-line object-cover" loading="lazy" />
                        {:else}
                            <span class="text-xs text-muted-foreground">{row.image ?? '—'}</span>
                        {/if}
                    </td>
                    <td class="max-w-56 px-4 py-2 text-xs text-muted-foreground" title={row.properties.map((p) => `${p.name}: ${p.value}`).join('\n')}>
                        {row.properties.length > 0 ? `${row.properties.length} шт.` : '—'}
                    </td>
                    <td class="px-4 py-2 text-muted-foreground">{row.unit ?? '—'}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{row.price ? formatMoney(row.price) : '—'}</td>
                    <td class="px-4 py-2">
                        <Badge
                            variant={row.action === 'add' ? 'default' : row.action === 'change' ? 'secondary' : row.action === 'delete' ? 'destructive' : 'outline'}
                        >{actionLabels[row.action]}</Badge>
                    </td>
                    <td class="max-w-64 px-4 py-2 text-xs text-destructive">
                        {row.errors ? row.errors.join(' ') : ''}
                    </td>
                </tr>
            {:else}
                <tr><td colspan="8" class="px-4 py-8 text-center text-muted-foreground">Строк нет</td></tr>
            {/each}
            </tbody>
        </table>
    </div>

    {#if rows.links.length > 3}
        <Pagination links={rows.links} />
    {/if}
</div>
{/if}
