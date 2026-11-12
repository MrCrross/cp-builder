<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import LayoutGrid from '@lucide/svelte/icons/layout-grid';
    import ScrollText from '@lucide/svelte/icons/scroll-text';
    import Users from '@lucide/svelte/icons/users';
    import type { Snippet } from 'svelte';
    import AppLogo from '@/app/ui/AppLogo.svelte';
    import NavMain from '@/app/ui/NavMain.svelte';
    import NavUser from '@/app/ui/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
        SidebarMenu,
        SidebarMenuButton,
        SidebarMenuItem,
    } from '@/shared/ui/sidebar';
    import { toUrl } from '@/shared/lib/utils';
    import admin from '@/routes/admin';
    import { dashboard } from '@/routes';
    import { edit as brandingEdit } from '@/routes/settings/branding';
    import { index as catalogIndex } from '@/routes/catalog';
    import { index as importsIndex } from '@/routes/imports';
    import { index as proposalsIndex } from '@/routes/proposals';
    import { index as projectsIndex } from '@/routes/projects';
    import { index as statisticsIndex } from '@/routes/statistics';
    import { index as templatesIndex } from '@/routes/templates';
    import { Building2, FileText, FolderKanban, LayoutTemplate, Package, Upload } from '@lucide/svelte';
    import BarChart3 from '@lucide/svelte/icons/bar-chart-3';
    import type { NavItem } from '@/app/lib/navigation';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const mainNavItems = $derived.by<NavItem[]>(() => {
        const permissions = page.props.auth?.permissions ?? [];
        const items: NavItem[] = [
            {
                title: 'Главная',
                href: dashboard(),
                icon: LayoutGrid,
            },
        ];

        if (permissions.includes('proposals.view')) {
            items.push({
                title: 'Коммерческие предложения',
                href: proposalsIndex(),
                icon: FileText,
            });
        }

        if (permissions.includes('projects.view')) {
            items.push({
                title: 'Проекты',
                href: projectsIndex(),
                icon: FolderKanban,
            });
        }

        if (permissions.includes('catalog.view')) {
            items.push({
                title: 'Номенклатура',
                href: catalogIndex(),
                icon: Package,
            });
        }

        if (permissions.includes('imports.run')) {
            items.push({
                title: 'Импорты',
                href: importsIndex(),
                icon: Upload,
            });
        }

        if (permissions.includes('templates.manage')) {
            items.push({
                title: 'Шаблоны',
                href: templatesIndex(),
                icon: LayoutTemplate,
            });
        }

        if (permissions.includes('statistics.view')) {
            items.push({
                title: 'Статистика',
                href: statisticsIndex(),
                icon: BarChart3,
            });
        }

        if (permissions.includes('users.manage')) {
            items.push({
                title: 'Пользователи',
                href: admin.users.index(),
                icon: Users,
            });
        }

        if (permissions.includes('branding.manage')) {
            items.push({
                title: 'Брендинг',
                href: brandingEdit(),
                icon: Building2,
            });
        }

        if (permissions.includes('audit.view')) {
            items.push({
                title: 'Журнал аудита',
                href: admin.audit.index(),
                icon: ScrollText,
            });
        }

        return items;
    });
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton size="lg" asChild>
                    {#snippet children(props)}
                        <Link
                            {...props}
                            href={toUrl(dashboard())}
                            class={props.class}
                        >
                            <AppLogo />
                        </Link>
                    {/snippet}
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} />
    </SidebarContent>

    <SidebarFooter>
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
