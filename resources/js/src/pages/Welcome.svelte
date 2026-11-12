<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import { toUrl } from '@/shared/lib/utils';
    import { dashboard, login } from '@/routes';

    const auth = $derived(page.props.auth);
</script>

<AppHead title="Добро пожаловать" />

<div
    class="flex min-h-screen flex-col items-center justify-center gap-8 bg-background p-6 text-foreground"
>
    <div class="text-center">
        <h1 class="text-3xl font-semibold tracking-tight">
            {page.props.name}
        </h1>
        <p class="mt-3 max-w-md text-muted-foreground">
            Внутренняя система подготовки коммерческих предложений. Вход
            доступен только для сотрудников компании.
        </p>
    </div>

    {#if auth.user}
        <Link
            href={toUrl(dashboard())}
            class="inline-flex h-10 items-center justify-center rounded-md bg-primary px-8 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
            data-test="welcome-dashboard-link"
        >
            Перейти в систему
        </Link>
    {:else}
        <Link
            href={toUrl(login())}
            class="inline-flex h-10 items-center justify-center rounded-md bg-primary px-8 text-sm font-medium text-primary-foreground shadow transition-colors hover:bg-primary/90"
            data-test="welcome-login-link"
        >
            Войти
        </Link>
    {/if}
</div>
