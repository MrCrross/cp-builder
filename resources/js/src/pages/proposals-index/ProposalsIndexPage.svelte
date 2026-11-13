<script module lang="ts">
    import { index } from '@/routes/proposals';

    export const layout = {
        breadcrumbs: [{ title: 'Коммерческие предложения', href: index() }],
    };
</script>

<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte';
    import { create, index as proposalsIndex } from '@/routes/proposals';
    import { destroy, issue, restore, archive, newDraft, rotateLink } from '@/routes/proposals';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import { Badge } from '@/shared/ui/badge';
    import { Button } from '@/shared/ui/button';
    import { Pagination } from '@/shared/ui/pagination';
    import { confirmDialog } from '@/shared/ui/confirm-dialog';
    import { Input } from '@/shared/ui/input';
    import { formatMoney, type ProposalListItem, type ProposalStatus } from '@/shared/api';

    interface PaginatedProposals {
        data: ProposalListItem[];
        links: { url: string | null; label: string; active: boolean }[];
        meta?: unknown;
    }

    const proposals = $derived((page.props.proposals as PaginatedProposals) ?? { data: [], links: [] });
    const filters = $derived(page.props.filters as { status?: string });

    const canMutate = $derived((page.props.auth?.permissions ?? []).includes('proposals.update'));

    const statusLabels: Record<ProposalStatus, string> = {
        draft: 'Черновик',
        issued: 'Выпущено',
        archived: 'Архив',
    };

    async function removeProposal(item: ProposalListItem) {
        const ok = await confirmDialog({
            title: `Удалить черновик «${item.title ?? 'без названия'}»?`,
            description: 'Действие необратимо.',
            variant: 'danger',
            confirmText: 'Удалить',
        });
        if (!ok) return;
        router.delete(destroy.url(item), { preserveScroll: true });
    }

    async function doIssue(item: ProposalListItem) {
        const ok = await confirmDialog({
            title: `Выпустить КП «${item.title ?? ''}»?`,
            description: 'Публичный документ будет создан.',
            confirmText: 'Выпустить',
        });
        if (!ok) return;
        router.post(issue.url(item), {}, { preserveScroll: true });
    }

    async function doArchive(item: ProposalListItem) {
        const ok = await confirmDialog({
            title: 'Архивировать КП?',
            description: 'Публичная ссылка будет закрыта.',
            variant: 'danger',
            confirmText: 'Архивировать',
        });
        if (!ok) return;
        router.post(archive.url(item), {}, { preserveScroll: true });
    }

    async function doRotate(item: ProposalListItem) {
        const ok = await confirmDialog({
            title: 'Заменить публичную ссылку?',
            description: 'Старая ссылка перестанет работать.',
            confirmText: 'Заменить',
        });
        if (!ok) return;
        router.post(rotateLink.url(item), {}, { preserveScroll: true });
    }
</script>

<AppHead title="Коммерческие предложения" />

<h1 class="sr-only">Коммерческие предложения</h1>

<div class="flex flex-col space-y-6 p-4">
    <div class="flex items-start justify-between gap-4">
        <Heading variant="small" title="Коммерческие предложения" description="Реестр документов: черновики, выпуск, архив." />
        {#if canMutate}
            <Button asChild data-test="create-proposal">
                {#snippet children(props)}
                    <a {...props} href={create.url()}>Новое КП</a>
                {/snippet}
            </Button>
        {/if}
    </div>

    <Form {...proposalsIndex.form()} class="flex max-w-sm gap-2" options={{ preserveScroll: true }}>
        {#snippet children({ processing })}
            <Input name="status" list="status-options" value={filters.status ?? ''} placeholder="Фильтр по статусу" />
            <datalist id="status-options">
                <option value="draft">Черновик</option>
                <option value="issued">Выпущено</option>
                <option value="archived">Архив</option>
            </datalist>
            <Button type="submit" variant="outline" disabled={processing}>Найти</Button>
        {/snippet}
    </Form>

    <div class="overflow-x-auto rounded-lg border border-line">
        <table class="w-full text-sm" data-test="proposals-table">
            <thead>
            <tr class="border-b border-line text-left text-muted-foreground">
                <th class="px-4 py-2 font-medium">Номер</th>
                <th class="px-4 py-2 font-medium">Название</th>
                <th class="px-4 py-2 font-medium">Статус</th>
                <th class="px-4 py-2 text-right font-medium">Сумма</th>
                <th class="px-4 py-2 font-medium">Версии</th>
                <th class="px-4 py-2 text-right font-medium">Действия</th>
            </tr>
            </thead>
            <tbody>
            {#each proposals.data as item (item.id)}
                <tr class="border-b border-line last:border-0">
                    <td class="px-4 py-2 font-medium tabular-nums">{item.number ?? '—'}</td>
                    <td class="px-4 py-2">
                        <a href={`/proposals/${item.id}`} class="hover:underline" data-test="proposal-link">
                            {item.title ?? 'Без названия'}
                        </a>
                    </td>
                    <td class="px-4 py-2">
                        <Badge variant={item.status === 'issued' ? 'default' : 'secondary'}>
                            {statusLabels[item.status]}
                        </Badge>
                    </td>
                    <td class="px-4 py-2 text-right tabular-nums">
                        {#if item.total_min != null && item.total_max != null}
                            {item.total_min === item.total_max
                                ? formatMoney(item.total_min)
                                : `${formatMoney(item.total_min)} – ${formatMoney(item.total_max)}`}
                        {:else}
                            —
                        {/if}
                    </td>
                    <td class="px-4 py-2 tabular-nums">{item.versions_count}</td>
                    <td class="px-4 py-2 text-right">
                        {#if canMutate}
                            <div class="flex justify-end gap-2">
                                {#if item.status === 'draft' && item.has_draft}
                                    <Button size="sm" variant="outline" onclick={() => doIssue(item)}>Выпустить</Button>
                                    <Button size="sm" variant="ghost" onclick={() => removeProposal(item)}>Удалить</Button>
                                {/if}
                                {#if item.status === 'issued'}
                                    {#if item.has_draft}
                                        <Badge variant="outline">Есть черновик v{item.versions_count + 1}</Badge>
                                    {:else}
                                        <Button size="sm" variant="outline" onclick={() => router.post(newDraft.url(item), {}, { preserveScroll: true })}>Новая версия</Button>
                                    {/if}
                                    <Button size="sm" variant="ghost" onclick={() => doArchive(item)}>В архив</Button>
                                    <Button size="sm" variant="ghost" onclick={() => doRotate(item)}>Заменить ссылку</Button>
                                {/if}
                                {#if item.status === 'archived'}
                                    <Button size="sm" variant="outline" onclick={() => router.post(restore.url(item), {}, { preserveScroll: true })}>Восстановить</Button>
                                {/if}
                            </div>
                        {/if}
                    </td>
                </tr>
            {:else}
                <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">Пока нет документов</td></tr>
            {/each}
            </tbody>
        </table>
    </div>

    {#if proposals.links.length > 3}
        <Pagination links={proposals.links} />
    {/if}
</div>
