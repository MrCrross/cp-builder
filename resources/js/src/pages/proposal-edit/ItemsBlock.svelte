<script lang="ts">
    import { formatMoney, type CatalogItemOption, type ProposalItemInput } from '@/shared/api';
    import { Button } from '@/shared/ui/button';
    import { confirmDialog } from '@/shared/ui/confirm-dialog';
    import { Input } from '@/shared/ui/input';
    import InputError from '@/shared/ui/InputError.svelte';
    import { lineAmount } from './blocks';
    import CatalogPickerModal from './CatalogPickerModal.svelte';
    import Trash2 from '@lucide/svelte/icons/trash-2';
    import ArrowUp from '@lucide/svelte/icons/arrow-up';
    import ArrowDown from '@lucide/svelte/icons/arrow-down';

    let {
        items = $bindable(),
        canEdit,
        errors = {},
    }: {
        items: ProposalItemInput[];
        canEdit: boolean;
        errors?: Record<string, string>;
    } = $props();

    let pickerOpen = $state(false);

    const blockTotals = $derived.by(() => {
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

    function pickItems(options: CatalogItemOption[]) {
        const picked = options.map((option) => ({
            catalog_item_id: option.id,
            name: option.name,
            description: option.description,
            unit: option.unit ?? 'шт',
            quantity_min: '1',
            quantity_max: '1',
            unit_price: option.price,
        }));
        // Единственный пустой placeholder-ряд заменяем выбранным, а не дублируем.
        const onlyPlaceholder = items.length === 1 && items[0].name.trim() === '';
        items = onlyPlaceholder ? picked : [...items, ...picked];
    }

    async function removeItem(index: number) {
        const item = items[index];
        const filled = item.name.trim() !== '' || (item.unit_price.trim() !== '' && item.unit_price !== '0.00');
        if (filled) {
            const ok = await confirmDialog({
                title: `Удалить позицию «${item.name || 'без названия'}»?`,
                variant: 'danger',
                confirmText: 'Удалить',
            });
            if (!ok) return;
        }
        items = items.filter((_, i) => i !== index);
    }

    function moveItem(index: number, delta: number) {
        const target = index + delta;
        if (target < 0 || target >= items.length) return;
        const next = [...items];
        [next[index], next[target]] = [next[target], next[index]];
        items = next;
    }
</script>

<div class="space-y-2">
    {#if canEdit}
        <div class="flex flex-wrap gap-2">
            <Button type="button" size="sm" variant="outline" onclick={() => (pickerOpen = true)} data-test="add-from-catalog">
                Из справочника
            </Button>
            <Button type="button" size="sm" variant="outline" onclick={addItem} data-test="add-item">Добавить позицию</Button>
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
                        <Input bind:value={item.name} placeholder="Название работы" aria-label="Название позиции {index + 1}" aria-invalid={Boolean(errors[`items.${index}.name`])} data-test="item-name" disabled={!canEdit} />
                        {#if item.catalog_item_id}
                            <span class="mt-1 inline-block rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">из справочника</span>
                        {/if}
                        <InputError message={errors[`items.${index}.name`]} />
                    </td>
                    <td class="px-3 py-2"><Input bind:value={item.unit} aria-label="Единица позиции {index + 1}" disabled={!canEdit} /></td>
                    <td class="px-3 py-2">
                        <Input bind:value={item.quantity_min} inputmode="decimal" aria-label="Минимальное количество позиции {index + 1}" aria-invalid={Boolean(errors[`items.${index}.quantity_min`])} disabled={!canEdit} />
                        <InputError message={errors[`items.${index}.quantity_min`]} />
                    </td>
                    <td class="px-3 py-2">
                        <Input bind:value={item.quantity_max} inputmode="decimal" aria-label="Максимальное количество позиции {index + 1}" aria-invalid={Boolean(errors[`items.${index}.quantity_max`])} disabled={!canEdit} />
                        <InputError message={errors[`items.${index}.quantity_max`]} />
                    </td>
                    <td class="px-3 py-2">
                        <Input bind:value={item.unit_price} inputmode="decimal" aria-label="Цена позиции {index + 1}" aria-invalid={Boolean(errors[`items.${index}.unit_price`])} disabled={!canEdit} />
                        <InputError message={errors[`items.${index}.unit_price`]} />
                    </td>
                    <td class="px-3 py-2 text-right">
                        {#if canEdit}
                            <div class="flex justify-end gap-0.5">
                                <Button type="button" size="icon" variant="ghost" class="size-8" title="Переместить выше" aria-label="Переместить позицию {index + 1} выше" disabled={index === 0} onclick={() => moveItem(index, -1)}>
                                    <ArrowUp class="size-4" />
                                </Button>
                                <Button type="button" size="icon" variant="ghost" class="size-8" title="Переместить ниже" aria-label="Переместить позицию {index + 1} ниже" disabled={index === items.length - 1} onclick={() => moveItem(index, 1)}>
                                    <ArrowDown class="size-4" />
                                </Button>
                                <Button type="button" size="icon" variant="ghost" class="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive" title="Удалить позицию" aria-label="Удалить позицию {index + 1}" onclick={() => removeItem(index)}>
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        {/if}
                    </td>
                </tr>
            {/each}
            </tbody>
        </table>
    </div>
    <div class="text-sm text-muted-foreground" data-test="block-totals-preview">
        Итог блока: {formatMoney(blockTotals.min)}
        {#if blockTotals.min !== blockTotals.max} – {formatMoney(blockTotals.max)}{/if}
        (окончательный считает сервер)
    </div>
</div>

<CatalogPickerModal bind:open={pickerOpen} onpickmany={pickItems} />
