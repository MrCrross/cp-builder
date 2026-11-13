<script lang="ts">
    import type { Snippet } from 'svelte';
    import AppContent from '@/app/ui/AppContent.svelte';
    import AppShell from '@/app/ui/AppShell.svelte';
    import AppSidebar from '@/app/ui/AppSidebar.svelte';
    import AppSidebarHeader from '@/app/ui/AppSidebarHeader.svelte';
    import { ConfirmHost } from '@/shared/ui/confirm-dialog';
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

<AppShell variant="sidebar">
    <AppSidebar />
    <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
        <AppSidebarHeader {breadcrumbs} />
        {@render children?.()}
    </AppContent>
    <Toaster />
    <ConfirmHost />
</AppShell>
