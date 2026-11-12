<script lang="ts">
    import { cn } from '@/shared/lib/utils';
    import LoaderCircle from '@lucide/svelte/icons/loader-circle';

    let {
        class: className = '',
        ...rest
    }: {
        class?: string;
        [key: string]: unknown;
    } = $props();
</script>

<LoaderCircle class={cn('animate-spin', className)} {...rest} />
