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

<div data-slot="input-otp-group" class={cn('flex items-center', className)}>
    {@render children?.()}
</div>
