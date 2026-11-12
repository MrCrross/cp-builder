<script module lang="ts">
    import { dashboard } from '@/routes';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Главная',
                href: dashboard(),
            },
        ],
    };
</script>

<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import AppHead from '@/shared/ui/AppHead.svelte';

    const user = $derived(page.props.auth.user);
</script>

<AppHead title="Главная" />

<div class="space-y-6 p-4">
    <div>
        <h1 class="text-xl font-semibold">
            Здравствуйте, {user?.name ?? 'коллега'}
        </h1>
        <p class="mt-1 text-sm text-muted-foreground">
            Рабочий стол. Конструктор коммерческих предложений появится на
            следующем этапе.
        </p>
    </div>
</div>
