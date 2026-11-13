<script module lang="ts">
    import admin from '@/routes/admin';

    export const layout = {
        breadcrumbs: [{ title: 'Журнал аудита', href: admin.audit.index() }],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import { Button } from '@/shared/ui/button';
    import { Pagination } from '@/shared/ui/pagination';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import { index } from '@/routes/admin/audit';
    import type { AuditEntry, Paginated } from '@/shared/api';

    let {
        entries,
        filters,
        actions,
    }: {
        entries: Paginated<AuditEntry>;
        filters: { action: string | null; actor_id: number | null };
        actions: string[];
    } = $props();

    const actionLabels: Record<string, string> = {
        'user.created': 'Создан пользователь',
        'user.updated': 'Изменён пользователь',
        'user.deactivated': 'Пользователь деактивирован',
        'user.activated': 'Пользователь активирован',
        'user.password-reset-completed': 'Пароль установлен через ссылку',
    };

    function formatDate(value: string): string {
        return new Intl.DateTimeFormat('ru-RU', {
            dateStyle: 'short',
            timeStyle: 'medium',
        }).format(new Date(value));
    }

    function formatValue(value: unknown): string {
        if (value === null || value === undefined) return '—';
        if (typeof value === 'boolean') return value ? 'да' : 'нет';
        if (Array.isArray(value)) return value.map(formatValue).join(', ');
        if (typeof value === 'object') return JSON.stringify(value);

        return String(value);
    }
</script>

<AppHead title="Журнал аудита" />

<div class="space-y-6 p-4">
    <div>
        <h1 class="text-xl font-semibold">Журнал аудита</h1>
        <p class="text-sm text-muted-foreground">
            Кто, когда и что изменил. Пароли, токены и прочие секреты сюда не
            записываются.
        </p>
    </div>

    <Form {...index.form()} class="flex flex-wrap items-end gap-3">
        {#snippet children({ processing })}
            <div class="grid gap-2">
                <Label for="filter-action">Действие</Label>
                <select
                    id="filter-action"
                    name="action"
                    class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                >
                    <option value="">Все действия</option>
                    {#each actions as action (action)}
                        <option value={action} selected={filters.action === action}>
                            {actionLabels[action] ?? action}
                        </option>
                    {/each}
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="filter-actor">ID автора</Label>
                <Input
                    id="filter-actor"
                    type="number"
                    name="actor_id"
                    min="1"
                    value={filters.actor_id ?? ''}
                    class="w-32"
                />
            </div>
            <Button type="submit" variant="secondary" disabled={processing}>
                Применить
            </Button>
        {/snippet}
    </Form>

    <div class="overflow-hidden rounded-lg border">
        <table class="w-full text-sm" data-test="audit-table">
            <thead>
                <tr
                    class="border-b bg-muted/50 text-left text-xs uppercase text-muted-foreground"
                >
                    <th class="px-4 py-3 font-medium">Время</th>
                    <th class="px-4 py-3 font-medium">Автор</th>
                    <th class="px-4 py-3 font-medium">Действие</th>
                    <th class="px-4 py-3 font-medium">Объект</th>
                    <th class="px-4 py-3 font-medium">Request ID</th>
                    <th class="px-4 py-3 font-medium">Изменения</th>
                </tr>
            </thead>
            <tbody>
                {#each entries.data as entry (entry.id)}
                    <tr class="border-b align-top last:border-0">
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">
                            {formatDate(entry.created_at)}
                        </td>
                        <td class="px-4 py-3">
                            {#if entry.actor}
                                <div class="font-medium">{entry.actor.name}</div>
                                <div class="text-muted-foreground">
                                    {entry.actor.email}
                                </div>
                            {:else}
                                <span class="text-muted-foreground">Система</span>
                            {/if}
                        </td>
                        <td class="px-4 py-3">
                            {actionLabels[entry.action] ?? entry.action}
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {#if entry.subject_type}
                                {entry.subject_type} №{entry.subject_id}
                            {:else}
                                —
                            {/if}
                        </td>
                        <td
                            class="max-w-32 truncate px-4 py-3 font-mono text-xs text-muted-foreground"
                            title={entry.request_id ?? ''}
                        >
                            {entry.request_id ?? '—'}
                        </td>
                        <td class="px-4 py-3">
                            {#if entry.before || entry.after}
                                <dl class="space-y-1">
                                    {#each Object.keys({ ...entry.before, ...entry.after }) as key (key)}
                                        <div class="flex gap-2 text-xs">
                                            <dt class="text-muted-foreground">
                                                {key}:
                                            </dt>
                                            <dd>
                                                {#if entry.before && key in entry.before}
                                                    <span>
                                                        {formatValue(entry.before[key])}
                                                    </span>
                                                    {#if entry.after && key in entry.after}
                                                        <span
                                                            class="text-muted-foreground"
                                                            >→</span
                                                        >
                                                        <span>
                                                            {formatValue(
                                                                entry.after[key],
                                                            )}
                                                        </span>
                                                    {/if}
                                                {:else if entry.after && key in entry.after}
                                                    <span>
                                                        {formatValue(entry.after[key])}
                                                    </span>
                                                {/if}
                                            </dd>
                                        </div>
                                    {/each}
                                </dl>
                            {:else}
                                <span class="text-muted-foreground">—</span>
                            {/if}
                        </td>
                    </tr>
                {:else}
                    <tr>
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            Записей не найдено.
                        </td>
                    </tr>
                {/each}
            </tbody>
        </table>
    </div>

    {#if entries.last_page > 1}
        <Pagination links={entries.links} from={entries.from} to={entries.to} total={entries.total} />
    {/if}
</div>
