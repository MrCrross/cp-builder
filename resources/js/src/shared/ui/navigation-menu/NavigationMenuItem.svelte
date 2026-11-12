<script lang="ts">
    import type { Snippet } from 'svelte';
    import { cn } from '@/shared/lib/utils';

    let {
        class: className = '',
        children,
    }: {
        class?: string;
        children?: Snippet;
    } = $props();
</script>

<li class={cn('list-none', className)}>
    {@render children?.()}
</li>
