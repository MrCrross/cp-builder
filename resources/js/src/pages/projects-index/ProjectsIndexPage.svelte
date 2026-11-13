<script module lang="ts">
    import { index } from '@/routes/projects';

    export const layout = {
        breadcrumbs: [{ title: 'Проекты', href: index() }],
    };
</script>

<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte';
    import { destroy, index as projectsIndex, store, update } from '@/routes/projects';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import { Button } from '@/shared/ui/button';
    import { confirmDialog } from '@/shared/ui/confirm-dialog';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import type { ProjectListItem } from '@/shared/api';

    interface PaginatedProjects {
        data: ProjectListItem[];
        links: { url: string | null; label: string; active: boolean }[];
        meta?: unknown;
    }

    const projects = $derived((page.props.projects as PaginatedProjects) ?? { data: [], links: [] });
    const filters = $derived(page.props.filters as { search?: string });
    const canManage = $derived((page.props.auth?.permissions ?? []).includes('projects.manage'));

    let name = $state('');
    let clientName = $state('');
    let clientContact = $state('');

    let editingId = $state<number | null>(null);
    let editName = $state('');
    let editClientName = $state('');
    let editClientContact = $state('');

    function startEdit(project: ProjectListItem) {
        editingId = project.id;
        editName = project.name;
        editClientName = project.client_name ?? '';
        editClientContact = project.client_contact ?? '';
    }

    function saveEdit(project: ProjectListItem) {
        router.put(
            update.url(project),
            { name: editName, client_name: editClientName || null, client_contact: editClientContact || null },
            { preserveScroll: true, onSuccess: () => (editingId = null) },
        );
    }

    async function removeProject(project: ProjectListItem) {
        const ok = await confirmDialog({
            title: `Удалить проект «${project.name}»?`,
            description: 'Проект с КП удалить нельзя.',
            variant: 'danger',
            confirmText: 'Удалить',
        });
        if (!ok) return;
        router.delete(destroy.url(project), { preserveScroll: true });
    }

    function decodeHtml(text: string): string {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = text;
        return textarea.value;
    }
</script>

<AppHead title="Проекты" />

<h1 class="sr-only">Проекты</h1>

<div class="flex flex-col space-y-6 p-4">
    <div class="flex items-start justify-between gap-4">
        <Heading variant="small" title="Проекты" description="Группировка коммерческих предложений по клиентским проектам." />
    </div>

    <Form {...projectsIndex.form()} class="flex max-w-sm gap-2" options={{ preserveScroll: true }}>
        {#snippet children({ processing })}
            <Input name="search" value={filters.search ?? ''} placeholder="Поиск по названию или клиенту" />
            <Button type="submit" variant="outline" disabled={processing}>Найти</Button>
        {/snippet}
    </Form>

    {#if canManage}
        <div class="flex max-w-3xl flex-wrap items-end gap-2 rounded-lg border border-line p-4">
            <div class="grid min-w-48 flex-1 gap-2">
                <Label for="project-name">Название проекта</Label>
                <Input id="project-name" bind:value={name} maxlength={255} data-test="project-name" />
            </div>
            <div class="grid min-w-48 flex-1 gap-2">
                <Label for="project-client">Клиент</Label>
                <Input id="project-client" bind:value={clientName} maxlength={255} />
            </div>
            <div class="grid min-w-48 flex-1 gap-2">
                <Label for="project-contact">Контакт клиента</Label>
                <Input id="project-contact" bind:value={clientContact} maxlength={255} />
            </div>
            <Button
                onclick={() =>
                    router.post(store.url(), { name, client_name: clientName || null, client_contact: clientContact || null }, {
                        preserveScroll: true,
                        onSuccess: () => {
                            name = '';
                            clientName = '';
                            clientContact = '';
                        },
                    })}
                disabled={!name}
                data-test="create-project"
            >
                Создать
            </Button>
        </div>
    {/if}

    <div class="overflow-x-auto rounded-lg border border-line">
        <table class="w-full text-sm" data-test="projects-table">
            <thead>
            <tr class="border-b border-line text-left text-muted-foreground">
                <th class="px-4 py-2 font-medium">Название</th>
                <th class="px-4 py-2 font-medium">Клиент</th>
                <th class="px-4 py-2 font-medium">Контакт</th>
                <th class="px-4 py-2 text-right font-medium">КП</th>
                <th class="px-4 py-2 text-right font-medium">Действия</th>
            </tr>
            </thead>
            <tbody>
            {#each projects.data as project (project.id)}
                <tr class="border-b border-line last:border-0">
                    {#if editingId === project.id}
                        <td class="px-4 py-2"><Input bind:value={editName} maxlength={255} /></td>
                        <td class="px-4 py-2"><Input bind:value={editClientName} maxlength={255} /></td>
                        <td class="px-4 py-2"><Input bind:value={editClientContact} maxlength={255} /></td>
                        <td class="px-4 py-2 text-right tabular-nums">{project.proposals_count}</td>
                        <td class="px-4 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button size="sm" onclick={() => saveEdit(project)} disabled={!editName}>Сохранить</Button>
                                <Button size="sm" variant="ghost" onclick={() => (editingId = null)}>Отмена</Button>
                            </div>
                        </td>
                    {:else}
                        <td class="px-4 py-2 font-medium">
                            <a href={`/projects/${project.id}`} class="hover:underline" data-test="project-link">{project.name}</a>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{project.client_name ?? '—'}</td>
                        <td class="px-4 py-2 text-muted-foreground">{project.client_contact ?? '—'}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{project.proposals_count}</td>
                        <td class="px-4 py-2 text-right">
                            {#if canManage}
                                <div class="flex justify-end gap-2">
                                    <Button size="sm" variant="outline" onclick={() => startEdit(project)}>Редактировать</Button>
                                    <Button size="sm" variant="ghost" onclick={() => removeProject(project)}>Удалить</Button>
                                </div>
                            {/if}
                        </td>
                    {/if}
                </tr>
            {:else}
                <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">Проектов пока нет</td></tr>
            {/each}
            </tbody>
        </table>
    </div>

    {#if projects.links.length > 3}
        <div class="flex flex-wrap gap-1">
            {#each projects.links as link (link.label)}
                {#if link.url}
                    <Button size="sm" variant={link.active ? 'default' : 'outline'} href={link.url}>{decodeHtml(link.label)}</Button>
                {/if}
            {/each}
        </div>
    {/if}
</div>
