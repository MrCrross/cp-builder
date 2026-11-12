<script module lang="ts">
    export const layout = {
        title: 'Подтверждение пароля',
        description:
            'Это защищённая область. Подтвердите пароль, чтобы продолжить.',
    };
</script>

<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import AppHead from '@/shared/ui/AppHead.svelte';
    import InputError from '@/shared/ui/InputError.svelte';
    import PasswordInput from '@/shared/ui/PasswordInput.svelte';
    import { Button } from '@/shared/ui/button';
    import { Label } from '@/shared/ui/label';
    import { Spinner } from '@/shared/ui/spinner';
    import { store } from '@/routes/password/confirm';
</script>

<AppHead title="Подтверждение пароля" />

<Form {...store.form()} resetOnSuccess>
    {#snippet children({ errors, processing })}
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label for="password">Пароль</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                />
                <InputError message={errors.password} />
            </div>

            <div class="flex items-center">
                <Button
                    type="submit"
                    class="w-full"
                    disabled={processing}
                    data-test="confirm-password-button"
                >
                    {#if processing}<Spinner />{/if}
                    Подтвердить
                </Button>
            </div>
        </div>
    {/snippet}
</Form>
