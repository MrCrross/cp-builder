<script lang="ts">
    let {
        status,
        title,
        description,
    }: {
        status: number;
        title: string;
        description: string;
    } = $props();
</script>

<svelte:head>
    <title>{status} - {title}</title>
</svelte:head>

<div
    class="flex min-h-screen flex-col items-center justify-center gap-6 bg-background p-6 text-foreground"
>
    <div class="text-center">
        <p class="text-6xl font-semibold tabular-nums text-muted-foreground">
            {status}
        </p>
        <h1 class="mt-4 text-2xl font-semibold">{title}</h1>
        <p class="mt-2 max-w-md text-muted-foreground">{description}</p>
    </div>
    <a
        href="/"
        class="inline-flex h-9 items-center justify-center rounded-md border border-input bg-background px-4 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
    >
        На главную
    </a>
</div>
