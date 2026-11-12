<script module lang="ts">
    export const layout = {
        title: 'Вход в систему',
        description: 'Введите email и пароль, чтобы продолжить',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import InputError from '@/shared/ui/InputError.svelte';
    import PasswordInput from '@/shared/ui/PasswordInput.svelte';
    import TextLink from '@/shared/ui/TextLink.svelte';
    import { Button } from '@/shared/ui/button';
    import { Checkbox } from '@/shared/ui/checkbox';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import { Spinner } from '@/shared/ui/spinner';
    import { store } from '@/routes/login';
    import { request } from '@/routes/password';

    let {
        status = '',
        canResetPassword,
    }: {
        status?: string;
        canResetPassword: boolean;
    } = $props();
</script>

<AppHead title="Вход" />

{#if status}
    <div class="mb-4 text-center text-sm font-medium text-green-600">
        {status}
    </div>
{/if}

<Form
    {...store.form()}
    resetOnSuccess={['password']}
    class="flex flex-col gap-6"
>
    {#snippet children({ errors, processing })}
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autocomplete="email"
                    placeholder="user@example.com"
                    data-test="email-input"
                />
                <InputError message={errors.email} />
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="password">Пароль</Label>
                    {#if canResetPassword}
                        <TextLink href={request()} class="text-sm">
                            Забыли пароль?
                        </TextLink>
                    {/if}
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="Пароль"
                    data-test="password-input"
                />
                <InputError message={errors.password} />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" />
                    <span>Запомнить меня</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                disabled={processing}
                data-test="login-button"
            >
                {#if processing}<Spinner />{/if}
                Войти
            </Button>
        </div>
    {/snippet}
</Form>
