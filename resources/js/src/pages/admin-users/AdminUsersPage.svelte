<script module lang="ts">
    import admin from '@/routes/admin';

    export const layout = {
        breadcrumbs: [{ title: 'Пользователи', href: admin.users.index() }],
    };
</script>

<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import InputError from '@/shared/ui/InputError.svelte';
    import { Button } from '@/shared/ui/button';
    import { Pagination } from '@/shared/ui/pagination';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
        DialogTrigger,
    } from '@/shared/ui/dialog';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import { Spinner } from '@/shared/ui/spinner';
    import { activate, deactivate, index, resendInvite, store, update } from '@/routes/admin/users';
    import type { AdminUser, Paginated, Role } from '@/shared/api';

    let {
        users,
        filters,
    }: {
        users: Paginated<AdminUser>;
        filters: { q: string };
    } = $props();

    const roleLabels: Record<Role, string> = {
        administrator: 'Администратор',
        manager: 'Менеджер',
        viewer: 'Наблюдатель',
    };

    const currentUserId = $derived(page.props.auth.user?.id);
    const flashStatus = $derived(page.props.flash?.status);

    let editing = $state<AdminUser | null>(null);
    let deactivating = $state<AdminUser | null>(null);
</script>

<AppHead title="Пользователи" />

<div class="space-y-6 p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">Пользователи</h1>
            <p class="text-sm text-muted-foreground">
                Учётные записи команды, роли и доступ.
            </p>
        </div>

        <Dialog>
            <DialogTrigger>
                <Button data-test="create-user-button">
                    Пригласить пользователя
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form {...store.form()} resetOnSuccess options={{ preserveScroll: true }}>
                    {#snippet children({ errors, processing })}
                        <DialogTitle>Новый пользователь</DialogTitle>
                        <DialogDescription>
                            Пользователь получит письмо со ссылкой для
                            установки пароля. Случайный начальный пароль никому
                            не показывается.
                        </DialogDescription>

                        <div class="mt-4 grid gap-4">
                            <div class="grid gap-2">
                                <Label for="create-name">Имя</Label>
                                <Input
                                    id="create-name"
                                    name="name"
                                    required
                                    autocomplete="off"
                                    data-test="create-user-name"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div class="grid gap-2">
                                <Label for="create-email">Email</Label>
                                <Input
                                    id="create-email"
                                    type="email"
                                    name="email"
                                    required
                                    autocomplete="off"
                                    placeholder="user@example.com"
                                    data-test="create-user-email"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div class="grid gap-2">
                                <Label for="create-role">Роль</Label>
                                <select
                                    id="create-role"
                                    name="role"
                                    required
                                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                    data-test="create-user-role"
                                >
                                    <option value="manager">Менеджер</option>
                                    <option value="viewer">Наблюдатель</option>
                                    <option value="administrator">
                                        Администратор
                                    </option>
                                </select>
                                <InputError message={errors.role} />
                            </div>
                        </div>

                        <DialogFooter>
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="create-user-submit"
                            >
                                {#if processing}<Spinner />{/if}
                                Создать и отправить ссылку
                            </Button>
                        </DialogFooter>
                    {/snippet}
                </Form>
            </DialogContent>
        </Dialog>
    </div>

    {#if flashStatus}
        <div
            class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200"
            role="status"
        >
            {flashStatus}
        </div>
    {/if}

    <Form {...index.form()} class="flex max-w-sm gap-2">
        {#snippet children({ processing })}
            <Input
                type="search"
                name="q"
                value={filters.q}
                placeholder="Поиск по имени или email"
                aria-label="Поиск по имени или email"
            />
            <Button type="submit" variant="secondary" disabled={processing}>
                Найти
            </Button>
        {/snippet}
    </Form>

    <div class="overflow-hidden rounded-lg border">
        <table class="w-full text-sm" data-test="users-table">
            <thead>
                <tr class="border-b bg-muted/50 text-left text-xs uppercase text-muted-foreground">
                    <th class="px-4 py-3 font-medium">Пользователь</th>
                    <th class="px-4 py-3 font-medium">Роль</th>
                    <th class="px-4 py-3 font-medium">Статус</th>
                    <th class="px-4 py-3 font-medium">Пароль</th>
                    <th class="px-4 py-3 text-right font-medium">Действия</th>
                </tr>
            </thead>
            <tbody>
                {#each users.data as user (user.id)}
                    <tr class="border-b last:border-0" data-test="user-row">
                        <td class="px-4 py-3">
                            <div class="font-medium">{user.name}</div>
                            <div class="text-muted-foreground">{user.email}</div>
                        </td>
                        <td class="px-4 py-3">
                            {roleLabels[(user.roles[0] as Role) ?? 'viewer']}
                        </td>
                        <td class="px-4 py-3">
                            {#if user.is_active}
                                <span
                                    class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200"
                                >
                                    Активен
                                </span>
                            {:else}
                                <span
                                    class="inline-flex rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300"
                                >
                                    Деактивирован
                                </span>
                            {/if}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {#if user.password_set_at}
                                Установлен
                            {:else}
                                Ожидает установки
                            {/if}
                        </td>
                        <td class="px-4 py-3">
                            <div
                                class="flex justify-end gap-1"
                                data-test="user-actions"
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onclick={() => (editing = user)}
                                    data-test="edit-user-button"
                                >
                                    Изменить
                                </Button>

                                {#if !user.password_set_at}
                                    <Form
                                        {...resendInvite.form(user)}
                                        options={{ preserveScroll: true }}
                                    >
                                        {#snippet children({ processing })}
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                disabled={processing}
                                                data-test="resend-invite-button"
                                            >
                                                Отправить ссылку
                                            </Button>
                                        {/snippet}
                                    </Form>
                                {/if}

                                {#if user.is_active}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={user.id === currentUserId}
                                        onclick={() => (deactivating = user)}
                                        data-test="deactivate-user-button"
                                    >
                                        Деактивировать
                                    </Button>
                                {:else}
                                    <Form
                                        {...activate.form(user)}
                                        options={{ preserveScroll: true }}
                                    >
                                        {#snippet children({ processing })}
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                disabled={processing}
                                                data-test="activate-user-button"
                                            >
                                                Активировать
                                            </Button>
                                        {/snippet}
                                    </Form>
                                {/if}
                            </div>
                        </td>
                    </tr>
                {:else}
                    <tr>
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            Пользователей не найдено.
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>

    {#if users.last_page > 1}
        <Pagination links={users.links} from={users.from} to={users.to} total={users.total} />
    {/if}
</div>

<Dialog open={editing !== null} onOpenChange={(open) => !open && (editing = null)}>
    <DialogContent>
        {#if editing}
            {@const target = editing}
            <Form
                {...update.form(target)}
                resetOnSuccess
                options={{ preserveScroll: true }}
                onSuccess={() => (editing = null)}
            >
                {#snippet children({ errors, processing })}
                    <DialogTitle>Изменить пользователя</DialogTitle>
                    <DialogDescription>
                        {target.email}
                    </DialogDescription>

                    <div class="mt-4 grid gap-4">
                        <div class="grid gap-2">
                            <Label for="edit-name">Имя</Label>
                            <Input
                                id="edit-name"
                                name="name"
                                required
                                value={target.name}
                                data-test="edit-user-name"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div class="grid gap-2">
                            <Label for="edit-role">Роль</Label>
                            <select
                                id="edit-role"
                                name="role"
                                required
                                class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                disabled={target.id === currentUserId}
                                data-test="edit-user-role"
                            >
                                {#each Object.entries(roleLabels) as [value, label] (value)}
                                    <option
                                        {value}
                                        selected={target.roles[0] === value}
                                    >
                                        {label}
                                    </option>
                                {/each}
                            </select>
                            <InputError message={errors.role} />
                            {#if target.id === currentUserId}
                                <p class="text-xs text-muted-foreground">
                                    Свою роль может изменить другой
                                    администратор.
                                </p>
                            {/if}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="edit-user-submit"
                        >
                            {#if processing}<Spinner />{/if}
                            Сохранить
                        </Button>
                    </DialogFooter>
                {/snippet}
            </Form>
        {/if}
    </DialogContent>
</Dialog>

<Dialog
    open={deactivating !== null}
    onOpenChange={(open) => !open && (deactivating = null)}
>
    <DialogContent>
        {#if deactivating}
            {@const target = deactivating}
            <Form
                {...deactivate.form(target)}
                options={{ preserveScroll: true }}
                onSuccess={() => (deactivating = null)}
            >
                {#snippet children({ processing })}
                    <DialogTitle>Деактивировать пользователя?</DialogTitle>
                    <DialogDescription>
                        {target.name} ({target.email}) потеряет
                        доступ немедленно: все его сессии будут завершены.
                        Черновики и записи останутся в системе.
                    </DialogDescription>
                    <DialogFooter>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={processing}
                            data-test="confirm-deactivate-button"
                        >
                            {#if processing}<Spinner />{/if}
                            Деактивировать
                        </Button>
                    </DialogFooter>
                {/snippet}
            </Form>
        {/if}
    </DialogContent>
</Dialog>
