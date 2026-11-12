<script lang="ts">
    import { Link, router } from '@inertiajs/svelte';
    import LogOut from '@lucide/svelte/icons/log-out';
    import Settings from '@lucide/svelte/icons/settings';
    import {
        DropdownMenuGroup,
        DropdownMenuItem,
        DropdownMenuLabel,
        DropdownMenuSeparator,
    } from '@/shared/ui/dropdown-menu';
    import UserInfo from '@/app/ui/UserInfo.svelte';
    import { toUrl } from '@/shared/lib/utils';
    import { logout } from '@/routes';
    import { edit } from '@/routes/profile';
    import type { User } from '@/shared/api';

    let {
        user,
    }: {
        user: User;
    } = $props();

    function handleLogout(propsOnClick?: () => void) {
        return () => {
            propsOnClick?.();
            router.flushAll();
        };
    }
</script>

<DropdownMenuLabel class="p-0 font-normal">
    <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
        <UserInfo {user} showEmail={true} />
    </div>
</DropdownMenuLabel>
<DropdownMenuSeparator />
<DropdownMenuGroup>
    <DropdownMenuItem asChild>
        {#snippet children(props)}
            <Link
                class={props.class}
                href={toUrl(edit())}
                prefetch
                onclick={props.onClick}
            >
                <Settings class="mr-2 h-4 w-4" />
                Настройки
            </Link>
        {/snippet}
    </DropdownMenuItem>
</DropdownMenuGroup>
<DropdownMenuSeparator />
<DropdownMenuItem asChild>
    {#snippet children(props)}
        <Link
            class={props.class}
            href={logout()}
            as="button"
            onclick={handleLogout(props.onClick)}
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Выйти
        </Link>
    {/snippet}
</DropdownMenuItem>
