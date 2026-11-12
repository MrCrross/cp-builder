<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import { Button } from '@/shared/ui/button';
    import { Separator } from '@/shared/ui/separator';
    import { currentUrlState } from '@/app/lib/currentUrl.svelte';
    import { toUrl } from '@/shared/lib/utils';
    import { edit as editAppearance } from '@/routes/appearance';
    import { edit as editProfile } from '@/routes/profile';
    import { edit as editSecurity } from '@/routes/security';
    import type { NavItem } from '@/app/lib/navigation';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const sidebarNavItems: NavItem[] = [
        {
            title: 'Профиль',
            href: editProfile(),
        },
        {
            title: 'Безопасность',
            href: editSecurity(),
        },
        {
            title: 'Оформление',
            href: editAppearance(),
        },
    ];

    const url = currentUrlState();
</script>

<div class="px-4 py-6">
    <Heading
        title="Настройки"
        description="Профиль и параметры аккаунта"
    />

    <div class="flex flex-col lg:flex-row lg:space-x-12">
        <aside class="w-full max-w-xl lg:w-48">
            <nav
                class="flex flex-col space-y-1 space-x-0"
                aria-label="Settings"
            >
                {#each sidebarNavItems as item (toUrl(item.href))}
                    <Button
                        variant="ghost"
                        class="w-full justify-start {url.isCurrentUrl(
                            item.href,
                            url.currentUrl,
                        )
                            ? 'bg-muted'
                            : ''}"
                        asChild
                    >
                        {#snippet children(props)}
                            <Link href={toUrl(item.href)} class={props.class}>
                                {item.title}
                            </Link>
                        {/snippet}
                    </Button>
                {/each}
            </nav>
        </aside>

        <Separator class="my-6 lg:hidden" />

        <div class="flex-1 md:max-w-2xl">
            <section class="max-w-xl space-y-12">
                {@render children?.()}
            </section>
        </div>
    </div>
</div>
