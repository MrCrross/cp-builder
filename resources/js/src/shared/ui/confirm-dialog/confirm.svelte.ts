/**
 * Программный сервис подтверждений — единая точка замены window.confirm().
 *
 * Один экземпляр диалога (ConfirmHost в AppSidebarLayout) обслуживает все
 * вызовы: confirmDialog() возвращает Promise<boolean> (true — подтверждено).
 * Добавление/удаление подтверждений не требует правок шаблонов — только
 * вызов сервиса в обработчике.
 *
 * Пример:
 *   async function remove() {
 *       if (!await confirmDialog({ title: 'Удалить?', variant: 'danger', confirmText: 'Удалить' })) return;
 *       destroy();
 *   }
 */

export interface ConfirmOptions {
    title: string;
    description?: string;
    variant?: 'default' | 'danger';
    confirmText?: string;
    cancelText?: string;
    size?: 'sm' | 'md' | 'lg';
}

export interface ConfirmRequest {
    title: string;
    description?: string;
    variant: 'default' | 'danger';
    confirmText: string;
    cancelText: string;
    size: 'sm' | 'md' | 'lg';
}

let request: ConfirmRequest | null = $state(null);
let resolver: ((ok: boolean) => void) | null = null;

export function confirmDialog(options: ConfirmOptions): Promise<boolean> {
    // Новый запрос отменяет прежний незакрытый диалог
    resolver?.(false);
    request = {
        variant: 'default',
        confirmText: 'Подтвердить',
        cancelText: 'Отмена',
        size: 'md',
        ...options,
    };

    return new Promise<boolean>((resolve) => {
        resolver = resolve;
    });
}

export function getConfirmRequest(): ConfirmRequest | null {
    return request;
}

export function resolveConfirm(ok: boolean): void {
    request = null;
    const resolve = resolver;
    resolver = null;
    resolve?.(ok);
}
