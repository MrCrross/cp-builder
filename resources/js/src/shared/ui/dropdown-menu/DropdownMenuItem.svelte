<script lang="ts">
    import type { Snippet } from 'svelte';
    import { getContext } from 'svelte';
    import { DROPDOWN_MENU_CONTEXT, type DropdownMenuContext } from './context';
    import { cn } from '@/shared/lib/utils';

    type AsChildProps = {
        class?: string;
        onClick?: () => void;
        [key: string]: any;
    };

    let {
        asChild = false,
        class: className = '',
        onclick,
        children,
    }: {
        asChild?: boolean;
        class?: string;
        onclick?: () => void;
        children?: Snippet<[AsChildProps]>;
    } = $props();

    const { setOpen } = getContext<DropdownMenuContext>(DROPDOWN_MENU_CONTEXT);

    const handleClick = () => {
        onclick?.();
        setOpen(false);
    };

    const classes = () =>
        cn(
            'flex w-full cursor-pointer select-none items-center rounded-sm px-2 py-1.5 text-sm outline-none hover:bg-accent hover:text-accent-foreground',
            className,
        );
</script>

{#if asChild}
    {@render children?.({ class: classes(), onClick: handleClick })}
{:else}
    <button type="button" class={classes()} onclick={handleClick}>
        {@render children?.({})}
    </button>
{/if}
