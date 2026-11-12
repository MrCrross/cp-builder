<script module lang="ts">
    import { index } from '@/routes/catalog';

    export const layout = {
        breadcrumbs: [{ title: 'Номенклатура', href: index() }],
    };
</script>

<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte';
    import { index as catalogIndex } from '@/routes/catalog';
    import { destroy as destroyCategory, store as storeCategory, update as updateCategory } from '@/routes/catalog/categories';
    import { destroy as destroyItem, image as itemImage, store as storeItem, update as updateItem } from '@/routes/catalog/items';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import { Badge } from '@/shared/ui/badge';
    import { Button } from '@/shared/ui/button';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import { formatMoney, type CatalogCategoryListItem, type CatalogItemListItem } from '@/shared/api';

    interface PaginatedItems {
        data: CatalogItemListItem[];
        links: { url: string | null; label: string; active: boolean }[];
        meta?: unknown;
    }

    const categories = $derived((page.props.categories as CatalogCategoryListItem[]) ?? []);
    const items = $derived((page.props.items as PaginatedItems) ?? { data: [], links: [] });
    const filters = $derived(page.props.filters as { search?: string; category_id?: string; active?: string });
    const canManage = $derived((page.props.auth?.permissions ?? []).includes('catalog.manage'));

    function itemImageUrl(item: CatalogItemListItem): string | null {
        if (!item.image) return null;
        if (/^https?:\/\//.test(item.image)) return item.image;
        return itemImage.url({ query: { path: item.image } });
    }

    // -- Категории -------------------------------------------------------------
    let categoryName = $state('');
    let editingCategoryId = $state<number | null>(null);
    let editCategoryName = $state('');

    function createCategory() {
        router.post(storeCategory.url(), { name: categoryName }, {
            preserveScroll: true,
            onSuccess: () => (categoryName = ''),
        });
    }

    function removeCategory(category: CatalogCategoryListItem) {
        if (!confirm(`Удалить категорию «${category.name}»? Категорию с позициями удалить нельзя.`)) return;
        router.delete(destroyCategory.url(category), { preserveScroll: true });
    }

    // -- Позиции ---------------------------------------------------------------
    let itemName = $state('');
    let itemCategoryId = $state<number | ''>('');
    let itemUnit = $state('шт');
    let itemPrice = $state('0.00');

    let editingItemId = $state<number | null>(null);
    let editItem = $state<CatalogItemListItem | null>(null);
    let editItemCategoryId = $state<number | ''>('');

    function createItem() {
        router.post(
            storeItem.url(),
            {
                name: itemName,
                category_id: itemCategoryId === '' ? null : itemCategoryId,
                unit: itemUnit || null,
                price: itemPrice,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    itemName = '';
                    itemPrice = '0.00';
                },
            },
        );
    }

    function startEditItem(item: CatalogItemListItem) {
        editingItemId = item.id;
        editItem = { ...item };
        editItemCategoryId = item.category?.id ?? '';
    }

    function saveEditItem() {
        if (!editItem) return;
        router.put(
            updateItem.url(editItem),
            {
                name: editItem.name,
                category_id: editItemCategoryId === '' ? null : editItemCategoryId,
                description: editItem.description,
                unit: editItem.unit,
                price: editItem.price,
                active: editItem.active,
            },
            { preserveScroll: true, onSuccess: () => (editingItemId = null) },
        );
    }

    function toggleActive(item: CatalogItemListItem) {
        router.put(
            updateItem.url(item),
            { name: item.name, category_id: item.category?.id ?? null, description: item.description, unit: item.unit, price: item.price, active: !item.active },
            { preserveScroll: true },
        );
    }

    function removeItem(item: CatalogItemListItem) {
        if (!confirm(`Удалить позицию «${item.name}»? Позицию из документов удалить нельзя — отключите её.`)) return;
        router.delete(destroyItem.url(item), { preserveScroll: true });
    }

    function decodeHtml(text: string): string {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = text;
        return textarea.value;
    }
</script>

<AppHead title="Номенклатура" />

<h1 class="sr-only">Номенклатура</h1>

<div class="flex flex-col space-y-6 p-4">
    <Heading variant="small" title="Номенклатура" description="Справочник позиций для повторного использования в КП. Изменения не меняют уже сохранённые документы." />

    {#if canManage}
        <div class="flex max-w-xl items-end gap-2 rounded-lg border border-line p-4">
            <div class="grid flex-1 gap-2">
                <Label for="category-name">Новая категория</Label>
                <Input id="category-name" bind:value={categoryName} maxlength={255} data-test="category-name" />
            </div>
            <Button onclick={createCategory} disabled={!categoryName} data-test="create-category">Создать</Button>
        </div>
    {/if}

    <div class="flex flex-wrap gap-2">
        <Badge variant={!filters.category_id ? 'default' : 'outline'}>
            <a href={catalogIndex.url()}>Все категории</a>
        </Badge>
        {#each categories as category (category.id)}
            <Badge variant={String(category.id) === String(filters.category_id ?? '') ? 'default' : 'outline'}>
                <a href={`${catalogIndex.url()}?category_id=${category.id}`}>{category.name} · {category.items_count}</a>
            </Badge>
        {/each}
    </div>

    <Form {...catalogIndex.form()} class="flex max-w-2xl flex-wrap gap-2" options={{ preserveScroll: true }}>
        {#snippet children({ processing })}
            <input type="hidden" name="category_id" value={filters.category_id ?? ''} />
            <input type="hidden" name="active" value={filters.active ?? ''} />
            <Input name="search" value={filters.search ?? ''} placeholder="Поиск по названию" class="flex-1" />
            <Button type="submit" variant="outline" disabled={processing}>Найти</Button>
        {/snippet}
    </Form>

    {#if canManage}
        <div class="flex max-w-4xl flex-wrap items-end gap-2 rounded-lg border border-line p-4">
            <div class="grid min-w-56 flex-1 gap-2">
                <Label for="item-name">Новая позиция</Label>
                <Input id="item-name" bind:value={itemName} maxlength={500} data-test="item-name" />
            </div>
            <div class="grid gap-2">
                <Label for="item-category">Категория</Label>
                <select id="item-category" bind:value={itemCategoryId} class="rounded-md border border-line bg-background px-3 py-2 text-sm">
                    <option value="">Без категории</option>
                    {#each categories as category (category.id)}
                        <option value={category.id}>{category.name}</option>
                    {/each}
                </select>
            </div>
            <div class="grid w-20 gap-2">
                <Label for="item-unit">Ед.</Label>
                <Input id="item-unit" bind:value={itemUnit} maxlength={50} />
            </div>
            <div class="grid w-32 gap-2">
                <Label for="item-price">Цена, ₽</Label>
                <Input id="item-price" bind:value={itemPrice} inputmode="decimal" data-test="item-price" />
            </div>
            <Button onclick={createItem} disabled={!itemName} data-test="create-item">Создать</Button>
        </div>
    {/if}

    <div class="overflow-x-auto rounded-lg border border-line">
        <table class="w-full text-sm" data-test="catalog-items-table">
            <thead>
            <tr class="border-b border-line text-left text-muted-foreground">
                <th class="px-4 py-2 font-medium">Название</th>
                <th class="px-4 py-2 font-medium">Категория</th>
                <th class="px-4 py-2 font-medium">Ед.</th>
                <th class="px-4 py-2 text-right font-medium">Цена</th>
                <th class="px-4 py-2 font-medium">Статус</th>
                <th class="px-4 py-2 text-right font-medium">Действия</th>
            </tr>
            </thead>
            <tbody>
            {#each items.data as item (item.id)}
                <tr class="border-b border-line last:border-0">
                    {#if editingItemId === item.id && editItem}
                        <td class="px-4 py-2"><Input bind:value={editItem.name} maxlength={500} /></td>
                        <td class="px-4 py-2">
                            <select bind:value={editItemCategoryId} class="rounded-md border border-line bg-background px-2 py-1.5 text-sm">
                                <option value="">Без категории</option>
                                {#each categories as category (category.id)}
                                    <option value={category.id}>{category.name}</option>
                                {/each}
                            </select>
                        </td>
                        <td class="px-4 py-2"><Input bind:value={editItem.unit} maxlength={50} class="w-20" /></td>
                        <td class="px-4 py-2"><Input bind:value={editItem.price} inputmode="decimal" class="w-32" /></td>
                        <td class="px-4 py-2">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" bind:checked={editItem.active} /> Активна
                            </label>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <div class="flex justify-end gap-2">
                                <Button size="sm" onclick={saveEditItem} disabled={!editItem.name}>Сохранить</Button>
                                <Button size="sm" variant="ghost" onclick={() => (editingItemId = null)}>Отмена</Button>
                            </div>
                        </td>
                    {:else}
                        <td class="px-4 py-2 font-medium">
                            <div class="flex items-center gap-3">
                                {#if itemImageUrl(item)}
                                    <img src={itemImageUrl(item)} alt="" class="h-10 w-10 shrink-0 rounded border border-line object-cover" loading="lazy" />
                                {/if}
                                <div>
                                    {item.name}
                                    {#if item.source_url}
                                        <a href={item.source_url} target="_blank" rel="noopener noreferrer" class="ml-1 text-xs text-primary underline">источник</a>
                                    {/if}
                                    {#if item.properties.length > 0}
                                        <div class="text-xs font-normal text-muted-foreground" title={item.properties.map((p) => `${p.name}: ${p.value}`).join('\n')}>
                                            {item.properties.map((p) => `${p.name}: ${p.value}`).join(' · ')}
                                        </div>
                                    {/if}
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">{item.category?.name ?? '—'}</td>
                        <td class="px-4 py-2 text-muted-foreground">{item.unit ?? '—'}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{formatMoney(item.price)}</td>
                        <td class="px-4 py-2">
                            <Badge variant={item.active ? 'default' : 'secondary'}>{item.active ? 'Активна' : 'Отключена'}</Badge>
                        </td>
                        <td class="px-4 py-2 text-right">
                            {#if canManage}
                                <div class="flex justify-end gap-2">
                                    <Button size="sm" variant="outline" onclick={() => startEditItem(item)}>Редактировать</Button>
                                    <Button size="sm" variant="outline" onclick={() => toggleActive(item)}>
                                        {item.active ? 'Отключить' : 'Включить'}
                                    </Button>
                                    <Button size="sm" variant="ghost" onclick={() => removeItem(item)}>Удалить</Button>
                                </div>
                            {/if}
                        </td>
                    {/if}
                </tr>
            {:else}
                <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">Позиций пока нет</td></tr>
            {/each}
            </tbody>
        </table>
    </div>

    {#if items.links.length > 3}
        <div class="flex flex-wrap gap-1">
            {#each items.links as link (link.label)}
                {#if link.url}
                    <Button size="sm" variant={link.active ? 'default' : 'outline'} href={link.url}>{decodeHtml(link.label)}</Button>
                {/if}
            {/each}
        </div>
    {/if}
</div>
