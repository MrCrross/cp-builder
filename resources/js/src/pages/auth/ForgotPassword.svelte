<script module lang="ts">
    export const layout = {
        title: 'Восстановление пароля',
        description:
            'Укажите email — если аккаунт существует, мы отправим ссылку для установки нового пароля',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import InputError from '@/shared/ui/InputError.svelte';
    import TextLink from '@/shared/ui/TextLink.svelte';
    import { Button } from '@/shared/ui/button';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import { Spinner } from '@/shared/ui/spinner';
    import { login } from '@/routes';
    import { email } from '@/routes/password';

    let {
        status = '',
    }: {
        status?: string;
    } = $props();
</script>

<AppHead title="Восстановление пароля" />

{#if status}
    <div class="mb-4 text-center text-sm font-medium text-green-600">
        {status}
    </div>
{/if}

<div class="space-y-6">
    <Form {...email.form()}>
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    placeholder="user@example.com"
                    data-test="email-input"
                />
                <InputError message={errors.email} />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    type="submit"
                    class="w-full"
                    disabled={processing}
                    data-test="email-password-reset-link-button"
                >
                    {#if processing}<Spinner />{/if}
                    Отправить ссылку
                </Button>
            </div>
        {/snippet}
    </Form>

    <div class="space-x-1 text-center text-sm text-muted-foreground">
        <span>или</span>
        <TextLink href={login()}>вернуться ко входу</TextLink>
    </div>
</div>
