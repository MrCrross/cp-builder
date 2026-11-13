<script module lang="ts">
    import { index } from '@/routes/projects';

    export const layout = {
        breadcrumbs: [
            { title: 'Проекты', href: index() },
            // Название проекта подставляется в странице, breadcrumb дополняется там.
        ],
    };
</script>

<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { destroy, update } from '@/routes/projects';
    import { store as storeProposal } from '@/routes/proposals';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import { Badge } from '@/shared/ui/badge';
    import { Button } from '@/shared/ui/button';
    import ConfirmDialog from '@/shared/ui/confirm-dialog/ConfirmDialog.svelte';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import type { ProjectDetails, ProjectProposalRef } from '@/shared/api';

    const project = $derived(page.props.project as ProjectDetails);
    const proposals = $derived((page.props.proposals as ProjectProposalRef[]) ?? []);
    const canManageProjects = $derived((page.props.auth?.permissions ?? []).includes('projects.manage'));
    const canCreateProposals = $derived((page.props.auth?.permissions ?? []).includes('proposals.create'));

    let name = $state('');
    let clientName = $state('');
    let clientContact = $state('');
    let deleteDialogOpen = $state(false);

    $effect(() => {
        name = project.name;
        clientName = project.client_name ?? '';
        clientContact = project.client_contact ?? '';
    });

    const statusLabels: Record<ProjectProposalRef['status'], string> = {
        draft: 'Черновик',
        issued: 'Выпущено',
        archived: 'Архив',
    };

    function saveProject() {
        router.put(
            update.url(project),
            { name, client_name: clientName || null, client_contact: clientContact || null },
            { preserveScroll: true },
        );
    }

    function removeProject() {
        router.delete(destroy.url(project));
    }

    function createProposal() {
        router.post(storeProposal.url(), { project_id: project.id });
    }
</script>

<AppHead title={`Проект ${project.name}`} />

<h1 class="sr-only">Проект {project.name}</h1>

<div class="flex flex-col space-y-6 p-4">
    <div class="flex items-start justify-between gap-4">
        <Heading variant="small" title={project.name} description="Клиентские данные и коммерческие предложения проекта." />
        {#if canCreateProposals}
            <Button onclick={createProposal} data-test="create-proposal">Новое КП</Button>
        {/if}
    </div>

    {#if canManageProjects}
        <div class="flex max-w-3xl flex-wrap items-end gap-2 rounded-lg border border-line p-4">
            <div class="grid min-w-48 flex-1 gap-2">
                <Label for="edit-name">Название проекта</Label>
                <Input id="edit-name" bind:value={name} maxlength={255} />
            </div>
            <div class="grid min-w-48 flex-1 gap-2">
                <Label for="edit-client">Клиент</Label>
                <Input id="edit-client" bind:value={clientName} maxlength={255} />
            </div>
            <div class="grid min-w-48 flex-1 gap-2">
                <Label for="edit-contact">Контакт клиента</Label>
                <Input id="edit-contact" bind:value={clientContact} maxlength={255} />
            </div>
            <Button onclick={saveProject} disabled={!name}>Сохранить</Button>
            <Button variant="ghost" onclick={() => (deleteDialogOpen = true)}>Удалить проект</Button>
        </div>
        <ConfirmDialog
            bind:open={deleteDialogOpen}
            title={`Удалить проект «${project.name}»?`}
            description="Проект с КП удалить нельзя. Действие необратимо."
            variant="danger"
            confirmText="Удалить"
            onconfirm={removeProject}
        />
    {:else}
        <p class="text-sm text-muted-foreground">
            Клиент: {project.client_name ?? '—'} · Контакт: {project.client_contact ?? '—'}
        </p>
    {/if}

    <div class="overflow-x-auto rounded-lg border border-line">
        <table class="w-full text-sm" data-test="project-proposals-table">
            <thead>
            <tr class="border-b border-line text-left text-muted-foreground">
                <th class="px-4 py-2 font-medium">Номер</th>
                <th class="px-4 py-2 font-medium">Статус</th>
                <th class="px-4 py-2 font-medium">Обновлено</th>
                <th class="px-4 py-2 text-right font-medium">Действия</th>
            </tr>
            </thead>
            <tbody>
            {#each proposals as proposal (proposal.id)}
                <tr class="border-b border-line last:border-0">
                    <td class="px-4 py-2 font-medium tabular-nums">{proposal.number ?? '—'}</td>
                    <td class="px-4 py-2">
                        <Badge variant={proposal.status === 'issued' ? 'default' : 'secondary'}>{statusLabels[proposal.status]}</Badge>
                        {#if proposal.has_draft && proposal.status !== 'draft'}
                            <Badge variant="outline">есть черновик</Badge>
                        {/if}
                    </td>
                    <td class="px-4 py-2 text-muted-foreground">{proposal.updated_at ?? '—'}</td>
                    <td class="px-4 py-2 text-right">
                        <Button size="sm" variant="outline" href={`/proposals/${proposal.id}`}>Открыть</Button>
                    </td>
                </tr>
            {:else}
                <tr><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">В проекте пока нет КП</td></tr>
            {/each}
            </tbody>
        </table>
    </div>
</div>
