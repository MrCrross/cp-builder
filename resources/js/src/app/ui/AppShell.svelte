<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import { SidebarProvider } from '@/shared/ui/sidebar';
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

    const isOpen = $derived(page.props.sidebarOpen);
</script>

{#if variant === 'header'}
    <div class="flex min-h-screen w-full flex-col {className}">
        {@render children?.()}
    </div>
{:else}
    <SidebarProvider defaultOpen={isOpen}>
        {@render children?.()}
    </SidebarProvider>
{/if}
