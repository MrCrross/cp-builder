<script module lang="ts">
    import { index } from '@/routes/statistics';

    export const layout = {
        breadcrumbs: [{ title: 'Статистика', href: index() }],
    };
</script>

<script lang="ts">
    import { Form, page } from '@inertiajs/svelte';
    import { index as statisticsIndex } from '@/routes/statistics';
    import { edit as proposalsEdit } from '@/routes/proposals';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import { Button } from '@/shared/ui/button';
    import type {
        StatisticsBreakdowns,
        StatisticsFilters,
        StatisticsFreshness,
        StatisticsProposalOption,
        StatisticsProposalRow,
        StatisticsSeriesPoint,
        StatisticsTotals,
    } from '@/shared/api';

    const totals = $derived((page.props.totals as StatisticsTotals) ?? {
        visits: 0,
        unique_visitors: 0,
        bot_requests: 0,
        proposals_with_visits: 0,
    });
    const series = $derived((page.props.series as StatisticsSeriesPoint[]) ?? []);
    const breakdowns = $derived((page.props.breakdowns as StatisticsBreakdowns) ?? {
        device_type: [],
        os: [],
    });
    const proposals = $derived((page.props.proposals as StatisticsProposalRow[]) ?? []);
    const proposalOptions = $derived((page.props.proposal_options as StatisticsProposalOption[]) ?? []);
    const filters = $derived((page.props.filters as StatisticsFilters) ?? { period: 30, proposal_id: null });
    const freshness = $derived((page.props.freshness as StatisticsFreshness) ?? {
        last_visit_at: null,
        generated_at: new Date().toISOString(),
    });

    const periodOptions = [
        { value: '7', label: '7 дней' },
        { value: '30', label: '30 дней' },
        { value: '90', label: '90 дней' },
    ];

    const maxVisits = $derived(Math.max(1, ...series.map((p) => p.visits)));
    const maxBreakdownVisits = $derived({
        device_type: Math.max(1, ...breakdowns.device_type.map((b) => b.visits)),
        os: Math.max(1, ...breakdowns.os.map((b) => b.visits)),
    });

    const breakdownLabels: Record<string, string> = {
        desktop: 'Десктоп',
        mobile: 'Мобильные',
        tablet: 'Планшеты',
        unknown: 'Не определено',
        Windows: 'Windows',
        macOS: 'macOS',
        Linux: 'Linux',
        Android: 'Android',
        iOS: 'iOS',
    };

    function formatNumber(value: number): string {
        return value.toLocaleString('ru-RU');
    }

    function formatDay(date: string): string {
        const [, month, day] = date.split('-');
        return `${day}.${month}`;
    }

    function formatDateTime(iso: string): string {
        return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short' });
    }

    function freshnessLabel(lastVisitAt: string | null): string {
        if (!lastVisitAt) return 'Просмотров пока не зафиксировано';

        const diffMinutes = Math.max(0, Math.round((Date.now() - new Date(lastVisitAt).getTime()) / 60000));

        if (diffMinutes < 1) return 'Последний просмотр: только что';
        if (diffMinutes < 60) return `Последний просмотр: ${diffMinutes} мин назад`;

        return `Последний просмотр: ${formatDateTime(lastVisitAt)}`;
    }

    function optionLabel(option: StatisticsProposalOption): string {
        return [option.number, option.title].filter(Boolean).join(' — ');
    }
</script>

<AppHead title="Статистика" />

<h1 class="sr-only">Статистика просмотров</h1>

<div class="flex flex-col space-y-6 p-4">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <Heading
            variant="small"
            title="Статистика"
            description="Просмотры публичных коммерческих предложений. Дневные агрегаты без персональных данных."
        />
        <p class="text-sm text-muted-foreground" data-test="statistics-freshness">
            {freshnessLabel(freshness.last_visit_at)}
        </p>
    </div>

    <Form
        {...statisticsIndex.form()}
        class="flex flex-wrap items-end gap-2"
        options={{ preserveScroll: true }}
    >
        {#snippet children({ processing })}
            <div class="grid gap-2">
                <span class="text-sm font-medium">Период</span>
                <select
                    name="period"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    value={String(filters.period)}
                    data-test="statistics-period"
                >
                    {#each periodOptions as option}
                        <option value={option.value}>{option.label}</option>
                    {/each}
                </select>
            </div>
            <div class="grid gap-2">
                <span class="text-sm font-medium">КП</span>
                <select
                    name="proposal_id"
                    class="h-9 max-w-72 rounded-md border border-input bg-transparent px-3 text-sm"
                    value={filters.proposal_id ?? ''}
                    data-test="statistics-proposal"
                >
                    <option value="">Все предложения</option>
                    {#each proposalOptions as option}
                        <option value={option.id}>{optionLabel(option)}</option>
                    {/each}
                </select>
            </div>
            <Button type="submit" variant="outline" disabled={processing}>Применить</Button>
        {/snippet}
    </Form>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-test="statistics-totals">
        <div class="rounded-lg border border-line p-4">
            <p class="text-sm text-muted-foreground">Просмотры</p>
            <p class="mt-1 text-2xl font-semibold">{formatNumber(totals.visits)}</p>
        </div>
        <div class="rounded-lg border border-line p-4">
            <p class="text-sm text-muted-foreground">Уникальные посетители</p>
            <p class="mt-1 text-2xl font-semibold">{formatNumber(totals.unique_visitors)}</p>
        </div>
        <div class="rounded-lg border border-line p-4">
            <p class="text-sm text-muted-foreground">КП с просмотрами</p>
            <p class="mt-1 text-2xl font-semibold">{formatNumber(totals.proposals_with_visits)}</p>
        </div>
        <div class="rounded-lg border border-line p-4">
            <p class="text-sm text-muted-foreground">Запросы ботов</p>
            <p class="mt-1 text-2xl font-semibold">{formatNumber(totals.bot_requests)}</p>
        </div>
    </div>

    <div class="rounded-lg border border-line p-4">
        <h2 class="mb-4 text-sm font-medium">Просмотры по дням</h2>
        {#if totals.visits === 0}
            <p class="text-sm text-muted-foreground" data-test="statistics-empty">
                За выбранный период просмотров не зафиксировано.
            </p>
        {:else}
            <div class="flex h-40 items-end gap-px overflow-x-auto" data-test="statistics-chart">
                {#each series as point (point.date)}
                    <div class="flex min-w-2 flex-1 flex-col items-center gap-1" title="{formatDay(point.date)}: {formatNumber(point.visits)} просмотров, {formatNumber(point.unique_visitors)} уникальных">
                        <div
                            class="w-full rounded-t bg-primary/25"
                            style="height: {Math.round((point.unique_visitors / maxVisits) * 100)}%"
                        ></div>
                        <div
                            class="w-full rounded-t bg-primary"
                            style="height: {Math.round((point.visits / maxVisits) * 100)}%"
                        ></div>
                    </div>
                {/each}
            </div>
            <div class="mt-2 flex items-center gap-4 text-xs text-muted-foreground">
                <span class="flex items-center gap-1">
                    <span class="inline-block h-2.5 w-2.5 rounded-sm bg-primary"></span> просмотры
                </span>
                <span class="flex items-center gap-1">
                    <span class="inline-block h-2.5 w-2.5 rounded-sm bg-primary/25"></span> уникальные посетители
                </span>
            </div>
        {/if}
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-lg border border-line p-4">
            <h2 class="mb-4 text-sm font-medium">Устройства</h2>
            {#each breakdowns.device_type as item (item.value)}
                <div class="mb-2">
                    <div class="mb-1 flex justify-between text-sm">
                        <span>{breakdownLabels[item.value] ?? item.value}</span>
                        <span class="text-muted-foreground">{formatNumber(item.visits)}</span>
                    </div>
                    <div class="h-2 rounded bg-muted">
                        <div
                            class="h-2 rounded bg-primary"
                            style="width: {Math.round((item.visits / maxBreakdownVisits.device_type) * 100)}%"
                        ></div>
                    </div>
                </div>
            {:else}
                <p class="text-sm text-muted-foreground">Нет данных за период.</p>
            {/each}
        </div>
        <div class="rounded-lg border border-line p-4">
            <h2 class="mb-4 text-sm font-medium">Операционные системы</h2>
            {#each breakdowns.os as item (item.value)}
                <div class="mb-2">
                    <div class="mb-1 flex justify-between text-sm">
                        <span>{breakdownLabels[item.value] ?? item.value}</span>
                        <span class="text-muted-foreground">{formatNumber(item.visits)}</span>
                    </div>
                    <div class="h-2 rounded bg-muted">
                        <div
                            class="h-2 rounded bg-primary"
                            style="width: {Math.round((item.visits / maxBreakdownVisits.os) * 100)}%"
                        ></div>
                    </div>
                </div>
            {:else}
                <p class="text-sm text-muted-foreground">Нет данных за период.</p>
            {/each}
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-line">
        <table class="w-full text-sm" data-test="statistics-proposals">
            <thead>
            <tr class="border-b border-line text-left text-muted-foreground">
                <th class="px-4 py-2 font-medium">КП</th>
                <th class="px-4 py-2 text-right font-medium">Просмотры</th>
                <th class="px-4 py-2 text-right font-medium">Уникальные</th>
                <th class="px-4 py-2 text-right font-medium">Боты</th>
            </tr>
            </thead>
            <tbody>
            {#each proposals as row (row.id)}
                <tr class="border-b border-line last:border-0">
                    <td class="px-4 py-2">
                        <a href={proposalsEdit.url(row.id)} class="text-primary hover:underline">
                            {row.number ?? '—'}{row.title ? ` · ${row.title}` : ''}
                        </a>
                    </td>
                    <td class="px-4 py-2 text-right">{formatNumber(row.visits)}</td>
                    <td class="px-4 py-2 text-right">{formatNumber(row.unique_visitors)}</td>
                    <td class="px-4 py-2 text-right">{formatNumber(row.bot_requests)}</td>
                </tr>
            {:else}
                <tr>
                    <td colspan="4" class="px-4 py-6 text-center text-muted-foreground">
                        Нет данных за период.
                    </td>
                </tr>
            {/each}
            </tbody>
        </table>
    </div>
</div>
