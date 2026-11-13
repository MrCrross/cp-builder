<script lang="ts">
    import { untrack } from 'svelte';
    import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/shared/ui/dialog';
    import { Button } from '@/shared/ui/button';
    import { Input } from '@/shared/ui/input';
    import { formatMoney, type CatalogItemOption } from '@/shared/api';
    import { search as catalogSearch } from '@/routes/catalog/items';

    /**
     * Модальное окно выбора позиций из справочника: поиск по названию и
     * свойствам, множественный выбор — добавляются все отмеченные позиции.
     */
    let {
        open = $bindable(false),
        onpickmany,
    }: {
        open?: boolean;
        onpickmany: (options: CatalogItemOption[]) => void;
    } = $props();

    let query = $state('');
    let results = $state<CatalogItemOption[]>([]);
    let selectedIds = $state<number[]>([]);
    let timer: ReturnType<typeof setTimeout> | null = null;

    async function runSearch() {
        const params = new URLSearchParams();
        if (query.trim() !== '') params.set('q', query.trim());
        const response = await fetch(`${catalogSearch.url()}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;
        const data = (await response.json()) as { items: CatalogItemOption[] };
        results = data.items;
    }

    function scheduleSearch() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => void runSearch(), 250);
    }

    function toggle(option: CatalogItemOption) {
        selectedIds = selectedIds.includes(option.id)
            ? selectedIds.filter((id) => id !== option.id)
            : [...selectedIds, option.id];
    }

    function addSelected() {
        const chosen = results.filter((option) => selectedIds.includes(option.id));
        if (chosen.length === 0) return;
        onpickmany(chosen);
        open = false;
    }

    $effect(() => {
        if (!open) return;

        // runSearch() читает query — без untrack эффект зависел бы от ввода
        // и сбрасывал поиск после каждого символа.
        untrack(() => {
            query = '';
            results = [];
            selectedIds = [];
            void runSearch();
        });
    });
</script>

<Dialog bind:open>
    <DialogContent class="max-w-2xl">
        <DialogTitle>Добавить из справочника</DialogTitle>
        <DialogDescription>
            Поиск по названию и свойствам номенклатуры. Отметьте нужные позиции —
            название, единица и цена скопируются в блок.
        </DialogDescription>
        <Input
            bind:value={query}
            placeholder="Название или свойство, например «кран» или «ДН100»"
            oninput={scheduleSearch}
            aria-label="Поиск по справочнику"
            data-test="catalog-picker-search"
        />
        <div class="mt-3 max-h-80 divide-y divide-border overflow-y-auto" data-test="catalog-picker">
            {#each results as option (option.id)}
                <label
                    class="flex cursor-pointer items-start gap-3 px-2 py-2 text-sm hover:bg-muted"
                    class:bg-muted={selectedIds.includes(option.id)}
                >
                    <input
                        type="checkbox"
                        class="mt-1"
                        checked={selectedIds.includes(option.id)}
                        onchange={() => toggle(option)}
                        data-test="catalog-picker-option"
                    />
                    <span class="flex w-full flex-col gap-1">
                        <span class="flex w-full items-center justify-between gap-2">
                            <span>
                                {option.name}
                                {#if option.category}<span class="text-muted-foreground">· {option.category}</span>{/if}
                            </span>
                            <span class="tabular-nums text-muted-foreground">{formatMoney(option.price)}</span>
                        </span>
                        {#if option.properties && option.properties.length > 0}
                            <span class="flex flex-wrap gap-1">
                                {#each option.properties as property (property.name + property.value)}
                                    <span class="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                        {property.name}: {property.value}
                                    </span>
                                {/each}
                            </span>
                        {/if}
                    </span>
                </label>
            {:else}
                <p class="px-2 py-3 text-sm text-muted-foreground">Ничего не найдено</p>
            {/each}
        </div>
        <div class="mt-3 flex items-center justify-between gap-2">
            <span class="text-xs text-muted-foreground" data-test="catalog-picker-selected">
                Выбрано: {selectedIds.length}
            </span>
            <div class="flex gap-2">
                <Button type="button" size="sm" variant="ghost" onclick={() => (open = false)}>Закрыть</Button>
                <Button type="button" size="sm" disabled={selectedIds.length === 0} onclick={addSelected} data-test="catalog-picker-add">
                    Добавить выбранные ({selectedIds.length})
                </Button>
            </div>
        </div>
    </DialogContent>
</Dialog>
