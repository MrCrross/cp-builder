<script lang="ts">
    import type { Snippet } from 'svelte';
    import { SidebarInset } from '@/shared/ui/sidebar';
    import { cn } from '@/shared/lib/utils';
    import type { AppVariant } from '@/shared/api';

    let {
        variant = 'sidebar',
        class: className = '',
        children,
    }: {
        variant?: AppVariant;
        class?: string;
        children?: Snippet;
    } = $props();
</script>

{#if variant === 'sidebar'}
    <SidebarInset class={className}>
        {@render children?.()}
    </SidebarInset>
{:else}
    <main
        class={cn(
            'mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl',
            className,
        )}
    >
        {@render children?.()}
    </main>
{/if}
