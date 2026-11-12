<script lang="ts">
    import { cn } from '@/shared/lib/utils';

    let { class: className = '', ...rest } = $props();
</script>

<img class={cn('aspect-square h-full w-full', className)} {...rest} />
