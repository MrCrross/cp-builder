<script module lang="ts">
    import { edit as editAppearance } from '@/routes/appearance';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Appearance settings',
                href: editAppearance(),
            },
        ],
    };
</script>

<script lang="ts">
    import AppearanceTabs from '@/app/ui/AppearanceTabs.svelte';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
</script>

<AppHead title="Оформление" />

<h1 class="sr-only">Appearance settings</h1>

<div class="space-y-6">
    <Heading
        variant="small"
        title="Оформление"
        description="Тема интерфейса"
    />
    <AppearanceTabs />
</div>
