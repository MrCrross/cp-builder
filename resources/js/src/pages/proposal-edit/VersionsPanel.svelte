<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { toast } from 'svelte-sonner';
    import branches from '@/routes/proposals/branches';
    import { edit } from '@/routes/proposals';
    import { newDraft } from '@/routes/proposals';
    import { Badge } from '@/shared/ui/badge';
    import { Button } from '@/shared/ui/button';
    import { confirmDialog } from '@/shared/ui/confirm-dialog';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import { formatMoney, type ProposalEditPayload } from '@/shared/api';

    let {
        proposal,
        canEdit,
    }: {
        proposal: ProposalEditPayload;
        canEdit: boolean;
    } = $props();

    const versionStateLabels: Record<string, string> = {
        draft: 'черновик',
        issued: 'выпущена',
        superseded: 'заменена',
    };

    let forkName = $state('');
    let forkVersionId = $state<number | ''>('');

    // База форка — только выпущенные версии: ветка сразу получает
    // публичный документ, черновик форкать публично бессмысленно.
    const forkableVersions = $derived(proposal.versions.filter((version) => version.state !== 'draft'));

    $effect(() => {
        forkVersionId = proposal.current_version?.state !== 'draft' && proposal.current_version
            ? proposal.current_version.id
            : (forkableVersions[0]?.id ?? '');
    });

    function switchBranch(name: string) {
        router.get(`${edit.url(proposal)}?branch=${encodeURIComponent(name)}`);
    }

    async function promoteBranch(branchId: number, name: string) {
        const ok = await confirmDialog({
            title: `Сделать ветку «${name}» основной?`,
            description:
                'Постоянная основная ссылка начнёт показывать текущую версию этой ветки. Прежняя основная ветка останется без публичной ссылки.',
            confirmText: 'Сделать основной',
        });
        if (!ok) return;
        router.post(branches.promote.url({ proposal, branch: branchId }), {}, { preserveScroll: true });
    }

    async function createDraftOnBranch(branchId: number, name: string) {
        const ok = await confirmDialog({
            title: `Создать новую версию на ветке «${name}»?`,
            description: 'Новый черновик будет создан из копии текущей версии ветки. Публичная страница не изменится, пока черновик не выпущен.',
            confirmText: 'Создать',
        });
        if (!ok) return;
        router.post(newDraft.url(proposal), { branch_id: branchId }, { preserveScroll: true });
    }

    async function createBranch() {
        if (forkName.trim() === '' || forkVersionId === '') return;
        const ok = await confirmDialog({
            title: 'Создать ветку?',
            description:
                'Ветка получит собственную постоянную публичную ссылку. Дальше на ветке можно создавать новые версии, не затрагивая остальные.',
            confirmText: 'Создать ветку',
        });
        if (!ok) return;
        router.post(
            branches.store.url(proposal),
            { name: forkName.trim(), version_id: forkVersionId },
            { preserveScroll: true },
        );
        forkName = '';
    }

    async function copyLink(url: string) {
        try {
            await navigator.clipboard.writeText(url);
            toast.success('Ссылка скопирована');
        } catch {
            toast.error('Не удалось скопировать — скопируйте вручную');
        }
    }
</script>

<div class="rounded-lg border border-line" data-test="branches-panel">
    <div class="border-b border-line px-4 py-3">
        <h2 class="text-base font-medium">Версии и ветки</h2>
        <p class="mt-0.5 text-xs text-muted-foreground">
            Основная ссылка КП постоянна и показывает текущую версию основной ветки. Ветка — отделённая линия
            версий со своей ссылкой: например, вариант «со скидкой» для конкретного клиента.
        </p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-line text-left text-muted-foreground">
                    <th class="px-4 py-2 font-medium">Ветка</th>
                    <th class="px-4 py-2 font-medium">Текущая версия</th>
                    <th class="px-4 py-2 font-medium">Черновик</th>
                    <th class="px-4 py-2 font-medium">Ссылка</th>
                    <th class="px-4 py-2 text-right font-medium">Действия</th>
                </tr>
            </thead>
            <tbody>
                {#each proposal.branches as branch (branch.id)}
                    <tr class="border-b border-line last:border-0" class:bg-accent={branch.name === proposal.active_branch}>
                        <td class="px-4 py-2">
                            <span class="font-medium">{branch.name}</span>
                            {#if branch.is_main}
                                <Badge variant="default" class="ml-2">Основная</Badge>
                            {/if}
                        </td>
                        <td class="px-4 py-2">
                            {#if branch.current_version}
                                v{branch.current_version.version}
                                <span class="text-muted-foreground">({versionStateLabels[branch.current_version.state] ?? branch.current_version.state})</span>
                                <div class="text-xs text-muted-foreground">
                                    {branch.current_version.issued_at
                                        ? new Date(branch.current_version.issued_at).toLocaleDateString('ru-RU')
                                        : ''}
                                    · {formatMoney(branch.current_version.total_min)}
                                    {branch.current_version.total_min !== branch.current_version.total_max
                                        ? ` – ${formatMoney(branch.current_version.total_max)}`
                                        : ''}
                                </div>
                            {:else}
                                <span class="text-muted-foreground">—</span>
                            {/if}
                        </td>
                        <td class="px-4 py-2">
                            {#if branch.draft_version}
                                <Badge variant="secondary">есть</Badge>
                            {:else}
                                <span class="text-muted-foreground">—</span>
                            {/if}
                        </td>
                        <td class="px-4 py-2">
                            {#if branch.public_url}
                                <div class="flex items-center gap-2">
                                    <span class="max-w-40 truncate font-mono text-xs text-muted-foreground" title={branch.public_url}>
                                        {branch.public_url}
                                    </span>
                                    <Button size="sm" variant="ghost" onclick={() => copyLink(branch.public_url ?? '')}>
                                        Копировать
                                    </Button>
                                </div>
                            {:else if branch.has_public_link}
                                <span class="text-xs text-muted-foreground" title="Ссылка создана до обновления, URL не сохранён. Замените ссылку — новый URL появится здесь.">
                                    URL недоступен — замените ссылку
                                </span>
                            {:else}
                                <span class="text-muted-foreground">нет</span>
                            {/if}
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex justify-end gap-2">
                                {#if branch.name !== proposal.active_branch && branch.draft_version}
                                    <Button size="sm" variant="outline" onclick={() => switchBranch(branch.name)}>
                                        Переключиться
                                    </Button>
                                {/if}
                                {#if canEdit && !branch.is_main && proposal.status === 'issued'}
                                    <Button size="sm" variant="outline" onclick={() => promoteBranch(branch.id, branch.name)}>
                                        Сделать основной
                                    </Button>
                                {/if}
                                {#if canEdit && !branch.draft_version}
                                    <Button size="sm" variant="ghost" onclick={() => createDraftOnBranch(branch.id, branch.name)}>
                                        Новая версия
                                    </Button>
                                {/if}
                            </div>
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>

    {#if canEdit && proposal.status !== 'archived' && forkableVersions.length > 0}
        <div class="flex flex-wrap items-end gap-2 border-t border-line px-4 py-3">
            <div class="grid gap-1.5">
                <Label for="fork-version">Отделить версию в ветку</Label>
                <select
                    id="fork-version"
                    bind:value={forkVersionId}
                    class="rounded-md border border-line bg-background px-3 py-2 text-sm"
                    data-test="fork-version-select"
                >
                    {#each forkableVersions as version (version.id)}
                        <option value={version.id}>
                            v{version.version} · {version.branch_name} · {versionStateLabels[version.state] ?? version.state}
                        </option>
                    {/each}
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="fork-name">Имя ветки</Label>
                <Input
                    id="fork-name"
                    bind:value={forkName}
                    placeholder="Например: skidka-10"
                    maxlength={120}
                    class="w-56"
                    data-test="fork-name-input"
                />
            </div>
            <Button
                size="sm"
                disabled={forkName.trim() === '' || forkVersionId === ''}
                onclick={createBranch}
                data-test="fork-branch"
            >
                Создать ветку
            </Button>
        </div>
    {/if}
</div>
