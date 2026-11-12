<script module lang="ts">
    export const layout = {
        title: 'Новый пароль',
        description: 'Введите новый пароль',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import InputError from '@/shared/ui/InputError.svelte';
    import PasswordInput from '@/shared/ui/PasswordInput.svelte';
    import { Button } from '@/shared/ui/button';
    import { Input } from '@/shared/ui/input';
    import { Label } from '@/shared/ui/label';
    import { Spinner } from '@/shared/ui/spinner';
    import { update } from '@/routes/password';

    let {
        token,
        email,
        passwordRules,
    }: {
        token: string;
        email: string;
        passwordRules: string;
    } = $props();
</script>

<AppHead title="Новый пароль" />

<Form
    {...update.form()}
    transform={(data) => ({ ...data, token, email })}
    resetOnSuccess={['password', 'password_confirmation']}
>
    {#snippet children({ errors, processing })}
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    value={email}
                    class="mt-1 block w-full"
                    readonly
                />
                <InputError message={errors.email} class="mt-2" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Пароль</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Новый пароль"
                    passwordrules={passwordRules}
                />
                <InputError message={errors.password} />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Повторите пароль</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Повторите пароль"
                    passwordrules={passwordRules}
                />
                <InputError message={errors.password_confirmation} />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                disabled={processing}
                data-test="reset-password-button"
            >
                {#if processing}<Spinner />{/if}
                Сохранить пароль
            </Button>
        </div>
    {/snippet}
</Form>
