<script module lang="ts">
    import { index } from '@/routes/imports';

    export const layout = {
        breadcrumbs: [{ title: 'Импорты', href: index() }],
    };
</script>

<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { show as importsShow, store as importsStore } from '@/routes/imports';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import { Badge } from '@/shared/ui/badge';
    import { Button } from '@/shared/ui/button';
    import FileDrop from '@/shared/ui/file-drop/FileDrop.svelte';
    import LoadingButton from '@/shared/ui/loading-button/LoadingButton.svelte';
    import { Label } from '@/shared/ui/label';
    import type { ImportJobSummary, ImportProfileOption, ImportProfileValue } from '@/shared/api';

    interface PaginatedJobs {
        data: ImportJobSummary[];
        links: { url: string | null; label: string; active: boolean }[];
    }

    const jobs = $derived((page.props.jobs as PaginatedJobs) ?? { data: [], links: [] });
    const profiles = $derived((page.props.profiles as ImportProfileOption[]) ?? []);

    const statusLabels: Record<string, string> = {
        uploaded: 'Загружен',
        validating: 'Проверка',
        awaiting_confirmation: 'Ожидает подтверждения',
        processing: 'Применение',
        completed: 'Завершён',
        completed_with_errors: 'Завершён с ошибками',
        failed: 'Ошибка',
        cancelled: 'Отменён',
    };

    const statusVariant = (status: string): 'default' | 'secondary' | 'destructive' | 'outline' => {
        if (status === 'failed') return 'destructive';
        if (status === 'completed') return 'default';
        if (status === 'completed_with_errors' || status === 'cancelled') return 'secondary';
        return 'outline';
    };

    let profile = $state<ImportProfileValue>('commerceml');
    let files = $state<File[]>([]);
    let uploadState = $state<'idle' | 'uploading' | 'done'>('idle');
    let uploadProgress = $state(0);
    const uploading = $derived(uploadState === 'uploading');

    function submitUpload() {
        if (files.length === 0 || uploading) return;

        const data = new FormData();
        data.append('profile', profile);
        for (const file of files) {
            data.append('files[]', file);
        }

        uploadState = 'uploading';
        uploadProgress = 0;
        router.post(importsStore.url(), data, {
            forceFormData: true,
            onProgress: (event) => {
                if (!event || !(event.total ?? 0)) {
                    uploadProgress = event && event.loaded > 0 ? 0.9 : 0;
                    return;
                }
                uploadProgress = event.loaded / (event.total as number);
            },
            onSuccess: () => {
                uploadState = 'done';
            },
            onFinish: () => {
                if (uploadState === 'uploading') {
                    uploadState = 'idle';
                }
                files = [];
            },
        });
    }

    function decodeHtml(text: string): string {
        const textarea = document.createElement('textarea');
        textarea.innerHTML = text;
        return textarea.value;
    }
</script>

<AppHead title="Импорты" />

<h1 class="sr-only">Импорты</h1>

<div class="flex flex-col space-y-6 p-4">
    <Heading
        variant="small"
        title="Импорты"
        description="Загрузка XML/YML-выгрузок в каталог номенклатуры: файл попадает в quarantine, разбирается в staging, применяется к каталогу только после вашего подтверждения."
    />

    <form
        class="flex max-w-4xl flex-wrap items-end gap-3 rounded-lg border border-line p-4"
        onsubmit={(event) => {
            event.preventDefault();
            submitUpload();
        }}
    >
        <div class="grid gap-2">
            <Label for="import-profile">Профиль</Label>
            <select id="import-profile" bind:value={profile} class="rounded-md border border-line bg-background px-3 py-2 text-sm">
                {#each profiles as option (option.value)}
                    <option value={option.value}>{option.label}</option>
                {/each}
            </select>
        </div>
        <div class="grid min-w-72 flex-1 gap-2">
            <Label>Файлы выгрузки (XML, до 5 шт., суммарно профиль один)</Label>
            <FileDrop
                bind:files
                phase={uploadState}
                progress={uploadProgress}
                maxFiles={5}
                accept=".xml,text/xml,application/xml"
                inputId="import-files"
                disabled={uploading}
            />
        </div>
        <LoadingButton
            type="submit"
            phase={uploadState === 'done' ? 'success' : uploadState === 'uploading' ? 'loading' : 'idle'}
            disabled={uploading || uploadState === 'done' || files.length === 0}
            data-test="import-upload"
        >
            Загрузить
        </LoadingButton>
    </form>

    <div class="overflow-x-auto rounded-lg border border-line">
        <table class="w-full text-sm" data-test="imports-table">
            <thead>
            <tr class="border-b border-line text-left text-muted-foreground">
                <th class="px-4 py-2 font-medium">#</th>
                <th class="px-4 py-2 font-medium">Профиль</th>
                <th class="px-4 py-2 font-medium">Статус</th>
                <th class="px-4 py-2 text-right font-medium">Строк</th>
                <th class="px-4 py-2 text-right font-medium">Добавит</th>
                <th class="px-4 py-2 text-right font-medium">Изменит</th>
                <th class="px-4 py-2 text-right font-medium">Отключит</th>
                <th class="px-4 py-2 text-right font-medium">Ошибок</th>
                <th class="px-4 py-2 font-medium">Кто</th>
                <th class="px-4 py-2 font-medium">Создан</th>
            </tr>
            </thead>
            <tbody>
            {#each jobs.data as job (job.id)}
                <tr class="border-b border-line last:border-0">
                    <td class="px-4 py-2 tabular-nums"><a href={importsShow.url(job)} class="text-accent hover:underline">{job.id}</a></td>
                    <td class="px-4 py-2 text-muted-foreground">{job.profile === 'commerceml' ? 'CommerceML 3.1' : 'YML'}</td>
                    <td class="px-4 py-2">
                        <Badge variant={statusVariant(job.status)}>{statusLabels[job.status] ?? job.status}</Badge>
                    </td>
                    <td class="px-4 py-2 text-right tabular-nums">{job.rows_total}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{job.rows_add}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{job.rows_change}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{job.rows_delete}</td>
                    <td class="px-4 py-2 text-right tabular-nums">{job.rows_error}</td>
                    <td class="px-4 py-2 text-muted-foreground">{job.user?.name ?? '—'}</td>
                    <td class="px-4 py-2 text-muted-foreground">{job.created_at ? new Date(job.created_at).toLocaleString('ru-RU') : '—'}</td>
                </tr>
            {:else}
                <tr><td colspan="10" class="px-4 py-8 text-center text-muted-foreground">Импортов пока не было</td></tr>
            {/each}
            </tbody>
        </table>
    </div>

    {#if jobs.links.length > 3}
        <div class="flex flex-wrap gap-1">
            {#each jobs.links as link (link.label)}
                {#if link.url}
                    <Button size="sm" variant={link.active ? 'default' : 'outline'} href={link.url}>{decodeHtml(link.label)}</Button>
                {/if}
            {/each}
        </div>
    {/if}
</div>
