<script lang="ts">
    import { Input } from '@/shared/ui/input';
    import InputError from '@/shared/ui/InputError.svelte';
    import { Button } from '@/shared/ui/button';
    import type { Stage } from './blocks';
    import Copy from '@lucide/svelte/icons/copy';
    import ArrowUp from '@lucide/svelte/icons/arrow-up';
    import ArrowDown from '@lucide/svelte/icons/arrow-down';
    import Trash2 from '@lucide/svelte/icons/trash-2';
    import Plus from '@lucide/svelte/icons/plus';

    /** Блок «Этапы работ»: пронумерованные строки «Название этапа» + «Срок». */
    let {
        stages = $bindable<Stage[]>([]),
        canEdit = true,
        errors = {},
    }: {
        stages?: Stage[];
        canEdit?: boolean;
        errors?: Record<string, string>;
    } = $props();

    function addStage() {
        stages = [...stages, { title: '', duration: '' }];
    }

    function removeStage(index: number) {
        const stage = stages[index];
        const filled = stage.title.trim() !== '' || stage.duration.trim() !== '';
        if (filled && !confirm(`Удалить этап «${stage.title || stage.duration}»?`)) return;
        stages = stages.filter((_, i) => i !== index);
    }

    function duplicateStage(index: number) {
        stages = [...stages.slice(0, index + 1), { ...stages[index] }, ...stages.slice(index + 1)];
    }

    function moveStage(index: number, delta: number) {
        const target = index + delta;
        if (target < 0 || target >= stages.length) return;
        const next = [...stages];
        [next[index], next[target]] = [next[target], next[index]];
        stages = next;
    }
</script>

<div data-test="stages-editor">
    {#if stages.length > 0}
        <div class="mb-1 hidden gap-2 pl-8 text-xs font-medium text-muted-foreground sm:flex">
            <span class="flex-1">Название этапа</span>
            <span class="w-36">Срок</span>
            <span class="w-28" aria-hidden="true"></span>
        </div>
    {/if}

    <div class="space-y-2">
        {#each stages as stage, index (index)}
            <div class="flex flex-wrap items-start gap-2" data-test="stage-row">
                <span class="w-6 pt-2.5 text-center text-sm text-muted-foreground tabular-nums" aria-hidden="true">
                    {index + 1}.
                </span>
                <div class="grid min-w-40 flex-1 gap-1">
                    <Input
                        bind:value={stage.title}
                        placeholder="Например: Проектирование"
                        aria-label="Название этапа {index + 1}"
                        disabled={!canEdit}
                    />
                    <InputError message={errors[`stages.${index}.title`]} />
                </div>
                <div class="grid w-36 gap-1">
                    <Input
                        bind:value={stage.duration}
                        placeholder="Например: 2 недели"
                        aria-label="Срок этапа {index + 1}"
                        disabled={!canEdit}
                    />
                    <InputError message={errors[`stages.${index}.duration`]} />
                </div>
                {#if canEdit}
                    <div class="flex w-28 items-center justify-end gap-0.5 pt-0.5">
                        <Button type="button" size="icon" variant="ghost" class="size-8" title="Дублировать этап" aria-label="Дублировать этап {index + 1}" onclick={() => duplicateStage(index)}>
                            <Copy class="size-4" />
                        </Button>
                        <Button type="button" size="icon" variant="ghost" class="size-8" title="Переместить выше" aria-label="Переместить этап {index + 1} выше" disabled={index === 0} onclick={() => moveStage(index, -1)}>
                            <ArrowUp class="size-4" />
                        </Button>
                        <Button type="button" size="icon" variant="ghost" class="size-8" title="Переместить ниже" aria-label="Переместить этап {index + 1} ниже" disabled={index === stages.length - 1} onclick={() => moveStage(index, 1)}>
                            <ArrowDown class="size-4" />
                        </Button>
                        <Button type="button" size="icon" variant="ghost" class="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive" title="Удалить этап" aria-label="Удалить этап {index + 1}" onclick={() => removeStage(index)}>
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                {/if}
            </div>
        {:else}
            <p class="rounded-md border border-dashed border-line px-3 py-4 text-sm text-muted-foreground">
                Этапов пока нет. Добавьте первый этап кнопкой ниже.
            </p>
        {/each}
    </div>

    {#if canEdit}
        <Button type="button" size="sm" variant="outline" class="mt-3" onclick={addStage} data-test="add-stage">
            <Plus class="size-4" />
            Добавить этап
        </Button>
    {/if}
</div>
