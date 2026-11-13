<script lang="ts">
    import type { Snippet } from 'svelte';
    import { Button } from '@/shared/ui/button';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
    } from '@/shared/ui/dialog';

    /**
     * Переиспользуемое модальное окно подтверждения (замена window.confirm).
     * Пропсы title/description покрывают простой случай; через snippet children
     * можно передать произвольное содержимое (формы, компоненты), через
     * snippet footer — свои кнопки. Управление видимостью — bind:open.
     */
    let {
        open = $bindable(false),
        title,
        description,
        variant = 'default',
        confirmText = 'Подтвердить',
        cancelText = 'Отмена',
        size = 'md',
        onconfirm,
        children,
        footer,
    }: {
        open?: boolean;
        title: string;
        description?: string;
        variant?: 'default' | 'danger';
        confirmText?: string;
        cancelText?: string;
        size?: 'sm' | 'md' | 'lg';
        onconfirm?: () => void;
        children?: Snippet;
        footer?: Snippet;
    } = $props();

    const sizes: Record<'sm' | 'md' | 'lg', string> = {
        sm: 'max-w-sm',
        md: 'max-w-lg',
        lg: 'max-w-2xl',
    };

    function confirmAction(): void {
        onconfirm?.();
        open = false;
    }
</script>

<Dialog bind:open>
    <DialogContent class={sizes[size]}>
        <DialogTitle>{title}</DialogTitle>
        {#if description}
            <DialogDescription>{description}</DialogDescription>
        {/if}
        {@render children?.()}
        {#if footer}
            {@render footer()}
        {:else}
            <DialogFooter>
                <Button variant="outline" onclick={() => (open = false)} type="button">
                    {cancelText}
                </Button>
                <Button
                    type="button"
                    variant={variant === 'danger' ? 'destructive' : 'default'}
                    onclick={confirmAction}
                    data-test="confirm-dialog-confirm"
                >
                    {confirmText}
                </Button>
            </DialogFooter>
        {/if}
    </DialogContent>
</Dialog>
