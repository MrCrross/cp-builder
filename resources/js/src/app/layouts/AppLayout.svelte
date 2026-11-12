<script lang="ts">
    import type { Snippet } from 'svelte';
    import AppLayout from '@/app/layouts/app/AppSidebarLayout.svelte';
    import type { BreadcrumbItem } from '@/app/lib/navigation';

    let {
        breadcrumbs = [],
        children,
    }: {
        breadcrumbs?: BreadcrumbItem[];
        children?: Snippet;
    } = $props();
</script>

<AppLayout {breadcrumbs}>
    {@render children?.()}
</AppLayout>
