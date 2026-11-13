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
     * Переиспользуемое модальное окно подтверждения. Для типового случая
     * «спросить и продолжить» используйте программный сервис confirmDialog()
     * из ./confirm.svelte — этот компонент нужен только как презентационный
     * слой (рендерит его ConfirmHost) или для кастомного содержимого.
     * Управление видимостью — bind:open.
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
        oncancel,
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
        oncancel?: () => void;
        children?: Snippet;
        footer?: Snippet;
    } = $props();

    const sizes: Record<'sm' | 'md' | 'lg', string> = {
        sm: 'max-w-sm',
        md: 'max-w-lg',
        lg: 'max-w-2xl',
    };

    let confirming = false;

    function confirmAction(): void {
        confirming = true;
        onconfirm?.();
        open = false;
        confirming = false;
    }

    function handleOpenChange(value: boolean): void {
        if (!value && !confirming) {
            oncancel?.();
        }
    }
</script>

<Dialog bind:open onOpenChange={handleOpenChange}>
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
