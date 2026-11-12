<script module lang="ts">
    import { edit } from '@/routes/security';

    export const layout = {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import Heading from '@/shared/ui/Heading.svelte';
    import InputError from '@/shared/ui/InputError.svelte';
    import PasswordInput from '@/shared/ui/PasswordInput.svelte';
    import { Button } from '@/shared/ui/button';
    import { Label } from '@/shared/ui/label';

    let { passwordRules }: { passwordRules: string } = $props();
</script>

<AppHead title="Безопасность" />

<h1 class="sr-only">Security settings</h1>

<div class="space-y-6">
    <Heading
        variant="small"
        title="Смена пароля"
        description="Используйте длинный случайный пароль"
    />

    <Form
        {...SecurityController.update.form()}
        class="space-y-6"
        options={{ preserveScroll: true }}
        resetOnSuccess
        resetOnError={['password', 'password_confirmation', 'current_password']}
    >
        {#snippet children({ errors, processing })}
            <div class="grid gap-2">
                <Label for="current_password">Текущий пароль</Label>
                <PasswordInput
                    id="current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Current password"
                />
                <InputError message={errors.current_password} />
            </div>

            <div class="grid gap-2">
                <Label for="password">Новый пароль</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="New password"
                    passwordrules={passwordRules}
                />
                <InputError message={errors.password} />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Повторите пароль</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Confirm password"
                    passwordrules={passwordRules}
                />
                <InputError message={errors.password_confirmation} />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    type="submit"
                    disabled={processing}
                    data-test="update-password-button"
                >
                    Save
                </Button>
            </div>
        {/snippet}
    </Form>
</div>
