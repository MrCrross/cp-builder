<script lang="ts">
    import type { Snippet } from 'svelte';
    import AppContent from '@/app/ui/AppContent.svelte';
    import AppHeader from '@/app/ui/AppHeader.svelte';
    import AppShell from '@/app/ui/AppShell.svelte';
    import { Toaster } from '@/shared/ui/sonner';
    import type { BreadcrumbItem } from '@/app/lib/navigation';

    let {
        breadcrumbs = [],
        children,
    }: {
        breadcrumbs?: BreadcrumbItem[];
        children?: Snippet;
    } = $props();
</script>

<AppShell variant="header">
    <AppHeader {breadcrumbs} />
    <AppContent variant="header">
        {@render children?.()}
    </AppContent>
    <Toaster />
</AppShell>
