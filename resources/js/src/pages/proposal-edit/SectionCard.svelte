<script lang="ts">
    import type { Snippet } from 'svelte';
    import { Checkbox } from '@/shared/ui/checkbox';
    import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/shared/ui/collapsible';
    import { Button } from '@/shared/ui/button';
    import { cn } from '@/shared/lib/utils';
    import GripVertical from '@lucide/svelte/icons/grip-vertical';
    import ChevronDown from '@lucide/svelte/icons/chevron-down';
    import ArrowUp from '@lucide/svelte/icons/arrow-up';
    import ArrowDown from '@lucide/svelte/icons/arrow-down';
    import Trash2 from '@lucide/svelte/icons/trash-2';

    /**
     * Каркас карточки блока КП: порядковый номер, объединённые чекбокс и
     * название (блок можно исключить из документа), порядок (drag-ручка +
     * стрелки), сворачивание и отделённое опасное действие «Удалить блок».
     */
    let {
        index,
        total,
        label,
        hint,
        enabled = $bindable(true),
        open = $bindable(true),
        canEdit = true,
        hasContent = false,
        dragging = false,
        dragOver = false,
        ondelete,
        onmove,
        ondragstart,
        ondragend,
        ondragover,
        ondrop,
        body,
    }: {
        index: number;
        total: number;
        label: string;
        hint: string;
        enabled?: boolean;
        open?: boolean;
        canEdit?: boolean;
        hasContent?: boolean;
        dragging?: boolean;
        dragOver?: boolean;
        ondelete: () => void;
        onmove: (delta: number) => void;
        ondragstart: () => void;
        ondragend: () => void;
        ondragover: () => void;
        ondrop: () => void;
        body: Snippet;
    } = $props();

    const checkboxId = $derived(`section-enabled-${index}`);
</script>

<Collapsible bind:open>
    <div
        class={cn(
            'rounded-lg border border-line bg-card transition-shadow',
            dragOver && 'ring-2 ring-primary',
            dragging && 'opacity-50',
        )}
        data-test="section-block"
        role="group"
        aria-label="Блок {index + 1}: {label}"
        ondragover={(event) => {
            if (!canEdit) return;
            event.preventDefault();
            ondragover();
        }}
        ondrop={(event) => {
            if (!canEdit) return;
            event.preventDefault();
            ondrop();
        }}
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 p-4">
            <button
                type="button"
                class="cursor-grab rounded p-1 text-muted-foreground hover:bg-muted disabled:cursor-default disabled:opacity-40"
                title="Перетащите, чтобы изменить порядок блоков"
                aria-label="Изменить порядок блока"
                disabled={!canEdit}
                draggable={canEdit}
                ondragstart={ondragstart}
                ondragend={ondragend}
            >
                <GripVertical class="size-4" />
            </button>
            <span
                class="grid size-6 shrink-0 place-content-center rounded-full bg-muted text-xs font-medium text-muted-foreground tabular-nums"
                aria-label="Блок {index + 1} из {total}"
            >
                {index + 1}
            </span>
            <div class="flex min-w-0 items-center gap-2">
                <Checkbox bind:checked={enabled} id={checkboxId} aria-label="Показывать блок в документе" />
                <div class="min-w-0">
                    <label for={checkboxId} class="cursor-pointer text-sm font-medium leading-tight">
                        {label}
                    </label>
                    <p class="text-xs text-muted-foreground">{hint}</p>
                </div>
            </div>
            {#if !enabled}
                <span class="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">Не попадёт в документ</span>
            {/if}
            <div class="ml-auto flex items-center gap-1">
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="size-8"
                    title="Переместить выше"
                    aria-label="Переместить блок выше"
                    disabled={!canEdit || index === 0}
                    onclick={() => onmove(-1)}
                >
                    <ArrowUp class="size-4" />
                </Button>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    class="size-8"
                    title="Переместить ниже"
                    aria-label="Переместить блок ниже"
                    disabled={!canEdit || index === total - 1}
                    onclick={() => onmove(1)}
                >
                    <ArrowDown class="size-4" />
                </Button>
                <CollapsibleTrigger aria-label={open ? 'Свернуть блок' : 'Развернуть блок'} title={open ? 'Свернуть' : 'Развернуть'}>
                    <ChevronDown class={cn('size-4 text-muted-foreground transition-transform', open && 'rotate-180')} />
                </CollapsibleTrigger>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    class="ml-1 border-destructive/30 text-destructive hover:bg-destructive/10 hover:text-destructive"
                    title={hasContent ? 'Удалить блок с введёнными данными' : 'Удалить блок'}
                    disabled={!canEdit}
                    onclick={ondelete}
                    data-test="delete-section"
                >
                    <Trash2 class="size-3.5" />
                    Удалить блок
                </Button>
            </div>
        </div>
        <CollapsibleContent class="px-4 pb-4">
            {@render body()}
        </CollapsibleContent>
    </div>
</Collapsible>
