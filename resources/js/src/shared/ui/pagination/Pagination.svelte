<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { cn } from '@/shared/lib/utils';
    import Button from '../button/Button.svelte';

    type PageLink = {
        url: string | null;
        label: string;
        active: boolean;
    };

    let {
        links,
        from = undefined,
        to = undefined,
        total = undefined,
        class: className = '',
    }: {
        links: PageLink[];
        from?: number | null;
        to?: number | null;
        total?: number | null;
        class?: string;
    } = $props();

    function decode(label: string): string {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = label;
        return textarea.value;
    }

    function go(url: string) {
        router.get(url, {}, { preserveScroll: true });
    }
</script>

<nav class={cn('flex flex-wrap items-center justify-between gap-3', className)} aria-label="Пагинация">
    {#if from != null && to != null && total != null}
        <span class="text-sm text-muted-foreground">{from}–{to} из {total}</span>
    {/if}
    <div class="flex flex-wrap items-center gap-1">
        {#each links as link (link.label)}
            {@const label = decode(link.label)}
            {#if link.url}
                <Button
                    size="sm"
                    variant={link.active ? 'default' : 'outline'}
                    aria-current={link.active ? 'page' : undefined}
                    onclick={() => link.url && go(link.url)}
                >
                    {label}
                </Button>
            {:else if label.trim() === '…'}
                <span class="px-2 text-sm text-muted-foreground">…</span>
            {:else}
                <Button size="sm" variant="outline" disabled>{label}</Button>
            {/if}
        {/each}
    </div>
</nav>
