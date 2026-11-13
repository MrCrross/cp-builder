<script lang="ts">
    import { Input } from '@/shared/ui/input';
    import InputError from '@/shared/ui/InputError.svelte';
    import { Button } from '@/shared/ui/button';
    import { confirmDialog } from '@/shared/ui/confirm-dialog';
    import type { LicenseItem } from './blocks';
    import Copy from '@lucide/svelte/icons/copy';
    import ArrowUp from '@lucide/svelte/icons/arrow-up';
    import ArrowDown from '@lucide/svelte/icons/arrow-down';
    import Trash2 from '@lucide/svelte/icons/trash-2';
    import Plus from '@lucide/svelte/icons/plus';

    /** Блок «Лицензии и расходы»: пронумерованные строки услуг с периодом и ценой. */
    let {
        items = $bindable<LicenseItem[]>([]),
        canEdit = true,
        errors = {},
    }: {
        items?: LicenseItem[];
        canEdit?: boolean;
        errors?: Record<string, string>;
    } = $props();

    function addItem() {
        items = [...items, { name: '', period: 'в месяц', price: '0.00' }];
    }

    async function removeItem(index: number) {
        const item = items[index];
        const filled = item.name.trim() !== '' || item.price.trim() !== '';
        if (filled) {
            const ok = await confirmDialog({
                title: `Удалить услугу «${item.name || item.period}»?`,
                variant: 'danger',
                confirmText: 'Удалить',
            });
            if (!ok) return;
        }
        items = items.filter((_, i) => i !== index);
    }

    function duplicateItem(index: number) {
        items = [...items.slice(0, index + 1), { ...items[index] }, ...items.slice(index + 1)];
    }

    function moveItem(index: number, delta: number) {
        const target = index + delta;
        if (target < 0 || target >= items.length) return;
        const next = [...items];
        [next[index], next[target]] = [next[target], next[index]];
        items = next;
    }
</script>

<div data-test="license-editor">
    {#if items.length > 0}
        <div class="mb-1 hidden gap-2 pl-8 text-xs font-medium text-muted-foreground sm:flex">
            <span class="flex-1">Услуга</span>
            <span class="w-32">Период</span>
            <span class="w-28">Стоимость</span>
            <span class="w-28" aria-hidden="true"></span>
        </div>
    {/if}

    <div class="space-y-2">
        {#each items as item, index (index)}
            <div class="flex flex-wrap items-start gap-2" data-test="license-row">
                <span class="w-6 pt-2.5 text-center text-sm text-muted-foreground tabular-nums" aria-hidden="true">
                    {index + 1}.
                </span>
                <div class="grid min-w-40 flex-1 gap-1">
                    <Input
                        bind:value={item.name}
                        placeholder="Например: Подписка на ПО"
                        aria-label="Услуга {index + 1}"
                        disabled={!canEdit}
                    />
                    <InputError message={errors[`items.${index}.name`]} />
                </div>
                <div class="grid w-32 gap-1">
                    <Input
                        bind:value={item.period}
                        placeholder="в месяц"
                        aria-label="Период услуги {index + 1}"
                        disabled={!canEdit}
                    />
                    <InputError message={errors[`items.${index}.period`]} />
                </div>
                <div class="grid w-28 gap-1">
                    <Input
                        bind:value={item.price}
                        placeholder="0.00"
                        inputmode="decimal"
                        aria-label="Стоимость услуги {index + 1}"
                        disabled={!canEdit}
                    />
                    <InputError message={errors[`items.${index}.price`]} />
                </div>
                {#if canEdit}
                    <div class="flex w-28 items-center justify-end gap-0.5 pt-0.5">
                        <Button type="button" size="icon" variant="ghost" class="size-8" title="Дублировать услугу" aria-label="Дублировать услугу {index + 1}" onclick={() => duplicateItem(index)}>
                            <Copy class="size-4" />
                        </Button>
                        <Button type="button" size="icon" variant="ghost" class="size-8" title="Переместить выше" aria-label="Переместить услугу {index + 1} выше" disabled={index === 0} onclick={() => moveItem(index, -1)}>
                            <ArrowUp class="size-4" />
                        </Button>
                        <Button type="button" size="icon" variant="ghost" class="size-8" title="Переместить ниже" aria-label="Переместить услугу {index + 1} ниже" disabled={index === items.length - 1} onclick={() => moveItem(index, 1)}>
                            <ArrowDown class="size-4" />
                        </Button>
                        <Button type="button" size="icon" variant="ghost" class="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive" title="Удалить услугу" aria-label="Удалить услугу {index + 1}" onclick={() => removeItem(index)}>
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                {/if}
            </div>
        {:else}
            <p class="rounded-md border border-dashed border-line px-3 py-4 text-sm text-muted-foreground">
                Услуг пока нет. Добавьте первую кнопкой ниже.
            </p>
        {/each}
    </div>

    {#if canEdit}
        <Button type="button" size="sm" variant="outline" class="mt-3" onclick={addItem} data-test="add-license">
            <Plus class="size-4" />
            Добавить услугу
        </Button>
    {/if}
</div>
