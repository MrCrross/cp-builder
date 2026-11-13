<script module lang="ts">
    import { index } from '@/routes/templates';

    export const layout = {
        breadcrumbs: [{ title: 'Шаблоны КП', href: index() }],
    };
</script>

<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { destroy, store, update } from '@/routes/templates';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import { Badge } from '@/shared/ui/badge';
    import { Button } from '@/shared/ui/button';
    import { confirmDialog } from '@/shared/ui/confirm-dialog';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import type { ProposalTemplateListItem } from '@/shared/api';

    const templates = $derived((page.props.templates as ProposalTemplateListItem[]) ?? []);
    const canManage = $derived((page.props.auth?.permissions ?? []).includes('templates.manage'));

    let name = $state('');
    let defaultTitle = $state('');

    function createTemplate() {
        router.post(store.url(), { name, default_title: defaultTitle }, {
            preserveScroll: true,
            onSuccess: () => {
                name = '';
                defaultTitle = '';
            },
        });
    }

    function toggleActive(template: ProposalTemplateListItem) {
        router.put(update.url(template), { name: template.name, default_title: template.default_title, active: !template.active }, { preserveScroll: true });
    }

    async function removeTemplate(template: ProposalTemplateListItem) {
        const ok = await confirmDialog({
            title: `Удалить шаблон «${template.name}»?`,
            description: 'Существующие КП сохранятся.',
            variant: 'danger',
            confirmText: 'Удалить',
        });
        if (!ok) return;
        router.delete(destroy.url(template), { preserveScroll: true });
    }
</script>

<AppHead title="Шаблоны КП" />

<h1 class="sr-only">Шаблоны коммерческих предложений</h1>

<div class="flex flex-col space-y-6 p-4">
    <Heading variant="small" title="Шаблоны" description="Начальные заголовки и блоки для новых КП. Контент копируется в документ при создании." />

    {#if canManage}
        <div class="flex max-w-2xl items-end gap-2 rounded-lg border border-line p-4">
            <div class="grid flex-1 gap-2">
                <Label for="tpl-name">Название шаблона</Label>
                <Input id="tpl-name" bind:value={name} maxlength={255} data-test="template-name" />
            </div>
            <div class="grid flex-1 gap-2">
                <Label for="tpl-title">Заголовок документа по умолчанию</Label>
                <Input id="tpl-title" bind:value={defaultTitle} maxlength={500} />
            </div>
            <Button onclick={createTemplate} disabled={!name || !defaultTitle} data-test="create-template">Создать</Button>
        </div>
    {/if}

    <div class="overflow-x-auto rounded-lg border border-line">
        <table class="w-full text-sm">
            <thead>
            <tr class="border-b border-line text-left text-muted-foreground">
                <th class="px-4 py-2 font-medium">Название</th>
                <th class="px-4 py-2 font-medium">Заголовок по умолчанию</th>
                <th class="px-4 py-2 font-medium">Статус</th>
                <th class="px-4 py-2 text-right font-medium">Действия</th>
            </tr>
            </thead>
            <tbody>
            {#each templates as template (template.id)}
                <tr class="border-b border-line last:border-0">
                    <td class="px-4 py-2 font-medium">{template.name}</td>
                    <td class="px-4 py-2 text-muted-foreground">{template.default_title}</td>
                    <td class="px-4 py-2">
                        <Badge variant={template.active ? 'default' : 'secondary'}>{template.active ? 'Активен' : 'Отключён'}</Badge>
                    </td>
                    <td class="px-4 py-2 text-right">
                        {#if canManage}
                            <div class="flex justify-end gap-2">
                                <Button size="sm" variant="outline" onclick={() => toggleActive(template)}>
                                    {template.active ? 'Отключить' : 'Включить'}
                                </Button>
                                <Button size="sm" variant="ghost" onclick={() => removeTemplate(template)}>Удалить</Button>
                            </div>
                        {/if}
                    </td>
                </tr>
            {:else}
                <tr><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Шаблонов пока нет</td></tr>
            {/each}
            </tbody>
        </table>
    </div>
</div>
