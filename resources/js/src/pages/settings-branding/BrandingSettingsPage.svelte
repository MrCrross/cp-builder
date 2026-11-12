<script module lang="ts">
    import { edit } from '@/routes/settings/branding';

    export const layout = {
        breadcrumbs: [{ title: 'Брендинг', href: edit() }],
    };
</script>

<script lang="ts">
    import { Form, page, router } from '@inertiajs/svelte';
    import { update } from '@/routes/settings/branding';
    import { destroy, store } from '@/routes/settings/branding/logo';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import InputError from '@/shared/ui/InputError.svelte';
    import { Button } from '@/shared/ui/button';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';

    interface LogoPayload {
        exists: boolean;
        url: string | null;
        width: number | null;
        height: number | null;
        bytes: number | null;
    }

    const organization = $derived(page.props.organization as {
        name: string;
        legal_name: string | null;
        city: string | null;
        website: string | null;
        slogan: string | null;
    });

    const logos = $derived(page.props.logos as { large: LogoPayload; small: LogoPayload });

    let largeInput: HTMLInputElement | undefined = $state();
    let smallInput: HTMLInputElement | undefined = $state();

    function upload(kind: 'large_logo' | 'small_logo', file: File | undefined) {
        if (!file) return;

        router.post(
            store.url({ kind }),
            { logo: file },
            {
                preserveScroll: true,
                onFinish: () => {
                    if (kind === 'large_logo' && largeInput) largeInput.value = '';
                    if (kind === 'small_logo' && smallInput) smallInput.value = '';
                },
            },
        );
    }

    function removeLogo(kind: 'large_logo' | 'small_logo') {
        if (!confirm(kind === 'large_logo' ? 'Удалить большой логотип?' : 'Удалить малый логотип?')) {
            return;
        }

        router.delete(destroy.url({ kind }), { preserveScroll: true });
    }
</script>

<AppHead title="Брендинг" />

<h1 class="sr-only">Брендинг организации</h1>

<div class="flex flex-col space-y-6 p-4">
    <Heading
        variant="small"
        title="Брендинг"
        description="Название, реквизиты и логотипы организации. Изменения применяются к новым документам; выпущенные КП остаются прежними."
    />

    <Form {...update.form()} class="space-y-6" options={{ preserveScroll: true }}>
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="name">Название организации</Label>
                <Input id="name" name="name" value={organization.name} required maxlength={255} />
                <InputError class="mt-2" message={errors.name} />
            </div>

            <div class="grid gap-2">
                <Label for="legal_name">Юридическое наименование</Label>
                <Input id="legal_name" name="legal_name" value={organization.legal_name ?? ''} maxlength={255} />
                <InputError class="mt-2" message={errors.legal_name} />
            </div>

            <div class="grid gap-2">
                <Label for="city">Город</Label>
                <Input id="city" name="city" value={organization.city ?? ''} maxlength={255} />
                <InputError class="mt-2" message={errors.city} />
            </div>

            <div class="grid gap-2">
                <Label for="website">Сайт</Label>
                <Input id="website" name="website" type="url" value={organization.website ?? ''} maxlength={255} placeholder="https://" />
                <InputError class="mt-2" message={errors.website} />
            </div>

            <div class="grid gap-2">
                <Label for="slogan">Слоган</Label>
                <Input id="slogan" name="slogan" value={organization.slogan ?? ''} maxlength={255} />
                <InputError class="mt-2" message={errors.slogan} />
            </div>

            <div class="flex items-center gap-4">
                <Button type="submit" disabled={processing} data-test="save-organization-button">Сохранить</Button>
            </div>
        {/snippet}
    </Form>

    <Heading variant="small" title="Логотипы" description="PNG, JPEG или WebP до 2 МБ, от 32×32 до 2048×2048 px." />

    <div class="grid gap-6 sm:grid-cols-2">
        <div class="space-y-3 rounded-lg border border-line p-4" data-test="logo-large">
            <div class="text-sm font-medium">Большой логотип</div>
            {#if logos.large.exists && logos.large.url}
                <img src={logos.large.url} alt="Большой логотип" class="max-h-24 rounded bg-white object-contain" />
                <div class="text-xs text-muted-foreground">
                    {logos.large.width}×{logos.large.height} px
                </div>
            {:else}
                <div class="text-sm text-muted-foreground">Не загружен</div>
            {/if}
            <div class="flex gap-2">
                <input
                    bind:this={largeInput}
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    class="text-sm"
                    data-test="logo-large-input"
                    onchange={(e) => upload('large_logo', (e.target as HTMLInputElement).files?.[0])}
                />
                {#if logos.large.exists}
                    <Button variant="outline" size="sm" onclick={() => removeLogo('large_logo')}>Удалить</Button>
                {/if}
            </div>
        </div>

        <div class="space-y-3 rounded-lg border border-line p-4" data-test="logo-small">
            <div class="text-sm font-medium">Малый логотип</div>
            {#if logos.small.exists && logos.small.url}
                <img src={logos.small.url} alt="Малый логотип" class="max-h-16 rounded bg-white object-contain" />
                <div class="text-xs text-muted-foreground">
                    {logos.small.width}×{logos.small.height} px
                </div>
            {:else}
                <div class="text-sm text-muted-foreground">Не загружен</div>
            {/if}
            <div class="flex gap-2">
                <input
                    bind:this={smallInput}
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    class="text-sm"
                    data-test="logo-small-input"
                    onchange={(e) => upload('small_logo', (e.target as HTMLInputElement).files?.[0])}
                />
                {#if logos.small.exists}
                    <Button variant="outline" size="sm" onclick={() => removeLogo('small_logo')}>Удалить</Button>
                {/if}
            </div>
        </div>
    </div>
</div>
